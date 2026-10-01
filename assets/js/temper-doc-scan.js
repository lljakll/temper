/**
 * Reusable document scan step.
 *
 * TemperDocScan.open({ onComplete: function (file) {} })
 * The caller receives one named PDF File and uploads it through its own path.
 * Ordinary image picks are not converted here.
 *
 * Flow: capture → Adjust (crop, flip, free rotate) → Enhance → page browser → named PDF.
 * Page detection uses Scanic (assets/vendor/scanic). Corners can always be dragged.
 */
(function () {
    if (window.TemperDocScan && typeof window.TemperDocScan.open === 'function') {
        return;
    }

    var MAX_EDGE = 2000;
    var MAX_PAGES = 12;
    var JPEG_QUALITY = 0.82;
    var SCANIC_URL = 'assets/vendor/scanic/scanic.js';

    var overlay = null;
    var bodyEl = null;
    var statusEl = null;
    var titleEl = null;
    var options = null;
    var pages = [];
    var sourceCanvas = null;
    var adjustedCanvas = null;
    var editor = null;
    var keyHandler = null;
    var focusHandler = null;
    var busy = false;
    var stylesInjected = false;
    var viewToken = 0;
    var showingResult = false;
    var lastCorners = null;
    var flipH = false;
    var flipV = false;
    var rotateDeg = 0;
    var grayPct = 0;
    var contrastPct = 100;
    var enhanceFrame = 0;

    function enc() {
        return new TextEncoder();
    }

    /**
     * Build a multi-page PDF whose pages are JPEG images (DeviceRGB / DCTDecode).
     * @param {Array<{jpeg:Uint8Array,width:number,height:number}>} pageList
     * @returns {Uint8Array}
     */
    function buildJpegPdf(pageList) {
        var list = pageList || [];
        if (!list.length) {
            throw new Error('Add at least one page.');
        }
        var text = enc();
        var objs = [];

        function pushText(s) {
            objs.push(text.encode(s));
        }

        var kids = [];
        var i;
        for (i = 0; i < list.length; i++) {
            kids.push((3 + i * 3) + ' 0 R');
        }
        pushText('1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n');
        pushText('2 0 obj\n<< /Type /Pages /Count ' + list.length + ' /Kids [' + kids.join(' ') + '] >>\nendobj\n');

        for (i = 0; i < list.length; i++) {
            var page = list[i];
            var jpeg = page.jpeg;
            var pxW = page.width | 0;
            var pxH = page.height | 0;
            if (!jpeg || !jpeg.length || pxW < 1 || pxH < 1) {
                throw new Error('A scanned page was empty.');
            }
            var pageNum = 3 + i * 3;
            var imgNum = pageNum + 1;
            var contentNum = pageNum + 2;
            var fit = Math.min(612 / pxW, 792 / pxH);
            var pw = Math.round(pxW * fit * 100) / 100;
            var ph = Math.round(pxH * fit * 100) / 100;
            pushText(
                pageNum + ' 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' + pw + ' ' + ph + ']'
                + ' /Resources << /XObject << /Im' + i + ' ' + imgNum + ' 0 R >> >>'
                + ' /Contents ' + contentNum + ' 0 R >>\nendobj\n'
            );

            var head = text.encode(
                imgNum + ' 0 obj\n<< /Type /XObject /Subtype /Image /Width ' + pxW
                + ' /Height ' + pxH
                + ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ' + jpeg.length
                + ' >>\nstream\n'
            );
            var tail = text.encode('\nendstream\nendobj\n');
            var imgObj = new Uint8Array(head.length + jpeg.length + tail.length);
            imgObj.set(head, 0);
            imgObj.set(jpeg, head.length);
            imgObj.set(tail, head.length + jpeg.length);
            objs.push(imgObj);

            var content = 'q\n' + pw + ' 0 0 ' + ph + ' 0 0 cm\n/Im' + i + ' Do\nQ\n';
            pushText(contentNum + ' 0 obj\n<< /Length ' + content.length + ' >>\nstream\n' + content + 'endstream\nendobj\n');
        }

        var header = text.encode('%PDF-1.4\n%\xE2\xE3\xCF\xD3\n');
        var parts = [header];
        var pos = header.length;
        var xref = [0];
        for (i = 0; i < objs.length; i++) {
            xref.push(pos);
            parts.push(objs[i]);
            pos += objs[i].length;
        }
        var xrefStr = 'xref\n0 ' + (objs.length + 1) + '\n0000000000 65535 f \n';
        for (i = 1; i < xref.length; i++) {
            xrefStr += String(xref[i]).padStart(10, '0') + ' 00000 n \n';
        }
        xrefStr += 'trailer\n<< /Size ' + (objs.length + 1) + ' /Root 1 0 R >>\nstartxref\n' + pos + '\n%%EOF\n';
        parts.push(text.encode(xrefStr));
        var total = 0;
        for (i = 0; i < parts.length; i++) total += parts[i].length;
        var out = new Uint8Array(total);
        var offset = 0;
        for (i = 0; i < parts.length; i++) {
            out.set(parts[i], offset);
            offset += parts[i].length;
        }
        return out;
    }

    function scanStamp() {
        var d = new Date();
        function p(n) { return String(n).padStart(2, '0'); }
        return 'scan_' + d.getFullYear() + p(d.getMonth() + 1) + p(d.getDate())
            + '_' + p(d.getHours()) + p(d.getMinutes()) + p(d.getSeconds());
    }

    function defaultDocumentName() {
        var fromOptions = options && options.fileName ? String(options.fileName) : '';
        fromOptions = fromOptions.replace(/\.pdf$/i, '').trim();
        return fromOptions || scanStamp();
    }

    function safePdfName(raw) {
        var name = String(raw || '').trim().replace(/\.pdf$/i, '');
        name = name.replace(/[\\/:*?"<>|\u0000-\u001f]/g, ' ').replace(/\s+/g, ' ').trim();
        if (!name) name = defaultDocumentName();
        if (name.length > 80) name = name.slice(0, 80).trim();
        return name + '.pdf';
    }

    function injectStyles() {
        if (stylesInjected || !document.getElementById) return;
        if (document.getElementById('temper-doc-scan-styles')) {
            stylesInjected = true;
            return;
        }
        var style = document.createElement('style');
        style.id = 'temper-doc-scan-styles';
        style.textContent = [
            '.temper-doc-scan{position:fixed;inset:0;z-index:12000;background:#121416;color:#f4f6f8;',
            'display:flex;flex-direction:column;font:16px/1.4 system-ui,sans-serif;}',
            '.temper-doc-scan *{box-sizing:border-box;}',
            '.temper-doc-scan-bar,.temper-doc-scan-foot{display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;',
            'padding:.6rem .75rem calc(.6rem + env(safe-area-inset-bottom));background:#1c2024;}',
            '.temper-doc-scan-bar{padding-top:calc(.6rem + env(safe-area-inset-top));}',
            '.temper-doc-scan-bar strong{margin-right:auto;}',
            '.temper-doc-scan-body{flex:1 1 auto;min-height:0;display:flex;flex-direction:column;overflow:auto;}',
            '.temper-doc-scan-editor{position:relative;flex:1 1 auto;min-height:220px;background:#000;overflow:hidden;touch-action:none;}',
            '.temper-doc-scan-status{padding:.45rem .75rem;color:#d5dbe2;font-size:.92rem;}',
            '.temper-doc-scan-capture,.temper-doc-scan-name{display:flex;flex-direction:column;gap:.75rem;padding:1.25rem .9rem;}',
            '.temper-doc-scan button{min-height:44px;border-radius:.4rem;border:1px solid #8ea0b5;',
            'background:#243040;color:#fff;padding:.4rem .8rem;font:inherit;}',
            '.temper-doc-scan button.primary{background:#0d6efd;border-color:#0d6efd;}',
            '.temper-doc-scan button.is-on{background:#0d6efd;border-color:#0d6efd;}',
            '.temper-doc-scan button:disabled{opacity:.55;}',
            '.temper-doc-scan-controls{display:flex;flex-direction:column;gap:.65rem;padding:.65rem .75rem 0;}',
            '.temper-doc-scan-controls label{display:flex;flex-direction:column;gap:.15rem;font-size:.92rem;}',
            '.temper-doc-scan-controls input[type=range]{width:100%;height:44px;accent-color:#0d6efd;}',
            '.temper-doc-scan-toggles{display:flex;gap:.5rem;flex-wrap:wrap;}',
            '.temper-doc-scan-preview-wrap{position:relative;flex:1 1 0;min-height:140px;background:#000;',
            'display:flex;align-items:center;justify-content:center;overflow:hidden;}',
            '.temper-doc-scan-preview{display:block;max-width:100%;max-height:100%;width:auto;height:auto;object-fit:contain;}',
            '.temper-doc-scan-browser{display:flex;flex-direction:column;gap:.6rem;padding:.75rem;}',
            '.temper-doc-scan-page{display:flex;gap:.6rem;align-items:center;background:#1c2024;border-radius:.4rem;padding:.45rem;}',
            '.temper-doc-scan-page img{width:4.5rem;height:6rem;object-fit:contain;background:#000;border-radius:.25rem;}',
            '.temper-doc-scan-page-main{flex:1 1 auto;min-width:0;display:flex;flex-direction:column;gap:.35rem;}',
            '.temper-doc-scan-page-actions{display:flex;gap:.35rem;flex-wrap:wrap;}',
            '.temper-doc-scan-page-actions button{min-height:40px;}',
            '.temper-doc-scan-name input{min-height:44px;font:inherit;padding:.45rem .65rem;border-radius:.4rem;',
            'border:1px solid #8ea0b5;background:#0e1216;color:#fff;}'
        ].join('');
        (document.head || document.documentElement).appendChild(style);
        stylesInjected = true;
    }

    function el(tag, attrs, children) {
        var node = document.createElement(tag);
        var key;
        attrs = attrs || {};
        for (key in attrs) {
            if (!Object.prototype.hasOwnProperty.call(attrs, key)) continue;
            if (key === 'class') node.className = attrs[key];
            else if (key === 'text') node.textContent = attrs[key];
            else if (key.indexOf('on') === 0 && typeof attrs[key] === 'function') {
                node.addEventListener(key.slice(2), attrs[key]);
            } else if (attrs[key] != null && attrs[key] !== false) {
                node.setAttribute(key, attrs[key] === true ? '' : String(attrs[key]));
            }
        }
        (children || []).forEach(function (child) {
            if (child) node.appendChild(child);
        });
        return node;
    }

    function setStatus(text) {
        if (statusEl) statusEl.textContent = text || '';
    }

    function setTitle(text) {
        if (titleEl) titleEl.textContent = text || 'Scan';
    }

    function clearBody() {
        if (bodyEl) bodyEl.textContent = '';
    }

    function focusPrimary() {
        if (!overlay) return;
        var btn = overlay.querySelector('button.primary');
        if (btn && btn.focus) btn.focus();
    }

    function destroyEditor() {
        if (editor && typeof editor.destroy === 'function') {
            try { editor.destroy(); } catch (e) { /* editor already gone */ }
        }
        editor = null;
    }

    function releaseUrl(url) {
        if (url && String(url).indexOf('blob:') === 0) {
            try { URL.revokeObjectURL(url); } catch (e) { /* ignore */ }
        }
    }

    function releasePage(page) {
        if (!page) return;
        releaseUrl(page.thumbUrl);
        releaseUrl(page.fullUrl);
    }

    function releasePages() {
        pages.forEach(releasePage);
        pages = [];
    }

    function resetPageWork() {
        sourceCanvas = null;
        adjustedCanvas = null;
        lastCorners = null;
        showingResult = false;
        flipH = false;
        flipV = false;
        rotateDeg = 0;
        grayPct = 0;
        contrastPct = 100;
        if (enhanceFrame) {
            cancelAnimationFrame(enhanceFrame);
            enhanceFrame = 0;
        }
    }

    function detachGuards() {
        if (keyHandler) {
            document.removeEventListener('keydown', keyHandler, true);
            keyHandler = null;
        }
        if (focusHandler) {
            document.removeEventListener('focusin', focusHandler, true);
            focusHandler = null;
        }
    }

    function close(force) {
        if (!overlay) return;
        if (!force && (pages.length || sourceCanvas)) {
            var ask = (typeof window.confirm === 'function') ? window.confirm : function () { return true; };
            if (!ask('Close and discard this scan?')) return;
        }
        viewToken += 1;
        destroyEditor();
        releasePages();
        resetPageWork();
        detachGuards();
        if (overlay.parentNode) overlay.parentNode.removeChild(overlay);
        overlay = null;
        bodyEl = null;
        statusEl = null;
        titleEl = null;
        options = null;
        busy = false;
    }

    function loadScript(url) {
        return new Promise(function (resolve, reject) {
            var script = document.createElement('script');
            script.src = url;
            script.async = true;
            script.onload = function () { resolve(); };
            script.onerror = function () { reject(new Error('Page detection could not be loaded.')); };
            (document.head || document.documentElement).appendChild(script);
        });
    }

    function ensureScanic() {
        if (window.scanic && typeof window.scanic.scanDocument === 'function'
            && typeof window.scanic.extractDocument === 'function'
            && typeof window.scanic.createCornerEditor === 'function') {
            return Promise.resolve(window.scanic);
        }
        return loadScript(SCANIC_URL).then(function () {
            if (!window.scanic || typeof window.scanic.createCornerEditor !== 'function') {
                throw new Error('Page detection could not be loaded.');
            }
            return window.scanic;
        });
    }

    function drawToCanvas(source, srcW, srcH) {
        var w = srcW;
        var h = srcH;
        var long = Math.max(w, h);
        if (long > MAX_EDGE && long > 0) {
            var scale = MAX_EDGE / long;
            w = Math.max(1, Math.round(w * scale));
            h = Math.max(1, Math.round(h * scale));
        }
        var canvas = document.createElement('canvas');
        canvas.width = w;
        canvas.height = h;
        var ctx = canvas.getContext('2d');
        if (!ctx) throw new Error('This browser cannot read that photo.');
        ctx.drawImage(source, 0, 0, w, h);
        return canvas;
    }

    function fileToCanvas(file) {
        if (typeof createImageBitmap === 'function') {
            return createImageBitmap(file).then(function (bitmap) {
                try {
                    return drawToCanvas(bitmap, bitmap.width, bitmap.height);
                } finally {
                    if (bitmap.close) bitmap.close();
                }
            }).catch(function () {
                return fileToCanvasViaImage(file);
            });
        }
        return fileToCanvasViaImage(file);
    }

    function fileToCanvasViaImage(file) {
        return new Promise(function (resolve, reject) {
            var url = URL.createObjectURL(file);
            var img = new Image();
            img.onload = function () {
                URL.revokeObjectURL(url);
                try {
                    resolve(drawToCanvas(img, img.naturalWidth || img.width, img.naturalHeight || img.height));
                } catch (err) {
                    reject(err);
                }
            };
            img.onerror = function () {
                URL.revokeObjectURL(url);
                reject(new Error('This photo could not be read. Use a JPEG or PNG.'));
            };
            img.src = url;
        });
    }

    function fullImageCorners(w, h) {
        var m = Math.max(1, Math.round(Math.min(w, h) * 0.02));
        return {
            topLeft: { x: m, y: m },
            topRight: { x: Math.max(m + 1, w - m), y: m },
            bottomRight: { x: Math.max(m + 1, w - m), y: Math.max(m + 1, h - m) },
            bottomLeft: { x: m, y: Math.max(m + 1, h - m) }
        };
    }

    function scaleCanvas(src) {
        var long = Math.max(src.width, src.height);
        if (long <= MAX_EDGE) return src;
        return drawToCanvas(src, src.width, src.height);
    }

    function flipCanvas(src, horizontal, vertical) {
        if (!horizontal && !vertical) return src;
        var canvas = document.createElement('canvas');
        canvas.width = src.width;
        canvas.height = src.height;
        var ctx = canvas.getContext('2d');
        if (!ctx) return src;
        ctx.translate(horizontal ? canvas.width : 0, vertical ? canvas.height : 0);
        ctx.scale(horizontal ? -1 : 1, vertical ? -1 : 1);
        ctx.drawImage(src, 0, 0);
        return canvas;
    }

    function rotateCanvas(src, degrees) {
        var deg = Number(degrees) || 0;
        if (Math.abs(deg) < 0.05) return src;
        var rad = deg * Math.PI / 180;
        var s = Math.abs(Math.sin(rad));
        var co = Math.abs(Math.cos(rad));
        var w = Math.max(1, Math.round(src.width * co + src.height * s));
        var h = Math.max(1, Math.round(src.width * s + src.height * co));
        var canvas = document.createElement('canvas');
        canvas.width = w;
        canvas.height = h;
        var ctx = canvas.getContext('2d');
        if (!ctx) return src;
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, w, h);
        ctx.translate(w / 2, h / 2);
        ctx.rotate(rad);
        ctx.drawImage(src, -src.width / 2, -src.height / 2);
        return canvas;
    }

    function clampByte(n) {
        if (n < 0) return 0;
        if (n > 255) return 255;
        return n;
    }

    function enhanceCanvas(src, gray, contrastValue) {
        var g = Math.max(0, Math.min(100, Number(gray) || 0)) / 100;
        var contrast = (Number(contrastValue) || 100) / 100;
        var canvas = document.createElement('canvas');
        canvas.width = src.width;
        canvas.height = src.height;
        var ctx = canvas.getContext('2d');
        if (!ctx) return src;
        ctx.drawImage(src, 0, 0);
        if (g === 0 && Math.abs(contrast - 1) < 0.001) return canvas;
        var image = ctx.getImageData(0, 0, canvas.width, canvas.height);
        var data = image.data;
        var i;
        for (i = 0; i < data.length; i += 4) {
            var r = data[i];
            var gc = data[i + 1];
            var b = data[i + 2];
            if (g > 0) {
                var y = (0.299 * r) + (0.587 * gc) + (0.114 * b);
                r = r + (y - r) * g;
                gc = gc + (y - gc) * g;
                b = b + (y - b) * g;
            }
            if (Math.abs(contrast - 1) >= 0.001) {
                r = ((r - 128) * contrast) + 128;
                gc = ((gc - 128) * contrast) + 128;
                b = ((b - 128) * contrast) + 128;
            }
            data[i] = clampByte(r);
            data[i + 1] = clampByte(gc);
            data[i + 2] = clampByte(b);
        }
        ctx.putImageData(image, 0, 0);
        return canvas;
    }

    function canvasToJpeg(canvas) {
        return new Promise(function (resolve, reject) {
            if (typeof canvas.toBlob !== 'function') {
                reject(new Error('This browser cannot build a JPEG page.'));
                return;
            }
            canvas.toBlob(function (blob) {
                if (!blob) {
                    reject(new Error('Could not save this page.'));
                    return;
                }
                if (typeof blob.arrayBuffer === 'function') {
                    blob.arrayBuffer().then(function (buf) {
                        resolve(new Uint8Array(buf));
                    }, reject);
                    return;
                }
                var reader = new FileReader();
                reader.onload = function () { resolve(new Uint8Array(reader.result)); };
                reader.onerror = function () { reject(new Error('Could not save this page.')); };
                reader.readAsArrayBuffer(blob);
            }, 'image/jpeg', JPEG_QUALITY);
        });
    }

    function formatDegrees(value) {
        var n = Math.round((Number(value) || 0) * 10) / 10;
        if (Math.abs(n - Math.round(n)) < 0.05) n = Math.round(n);
        return n + '°';
    }

    function readRotateControl() {
        var node = document.getElementById('temperDocScanRotate');
        if (node) rotateDeg = Number(node.value) || 0;
    }

    function currentCorners() {
        if (editor && typeof editor.getCorners === 'function') {
            return editor.getCorners();
        }
        return lastCorners;
    }

    function showCapture() {
        viewToken += 1;
        destroyEditor();
        resetPageWork();
        clearBody();
        setTitle('Scan');
        setStatus(pages.length
            ? 'Take a photo or choose an image for the next page.'
            : 'Take a photo or choose an image.');
        var camera = el('input', { type: 'file', accept: 'image/*', capture: 'environment', 'data-act': 'camera', style: 'display:none' });
        var library = el('input', { type: 'file', accept: 'image/*', 'data-act': 'library', style: 'display:none' });
        camera.addEventListener('change', function () { onPick(camera); });
        library.addEventListener('change', function () { onPick(library); });
        var capture = el('div', { class: 'temper-doc-scan-capture' }, [
            el('button', { type: 'button', class: 'primary', text: 'Take photo', onclick: function () { camera.click(); } }),
            el('button', { type: 'button', text: 'Choose image', onclick: function () { library.click(); } }),
            camera,
            library
        ]);
        if (pages.length) {
            capture.appendChild(el('button', {
                type: 'button',
                text: 'Pages',
                onclick: function () { showBrowser(); }
            }));
        }
        bodyEl.appendChild(capture);
        focusPrimary();
    }

    function onPick(input) {
        var file = input.files && input.files[0];
        input.value = '';
        if (!file) return;
        if (file.type && file.type.indexOf('image/') !== 0) {
            setStatus('Choose a photo. Other files stay on the regular upload.');
            return;
        }
        if (pages.length >= MAX_PAGES) {
            setStatus('This scan already has ' + MAX_PAGES + ' pages. Finish, then scan again if you need more.');
            return;
        }
        setStatus('Reading photo…');
        busy = true;
        fileToCanvas(file).then(function (canvas) {
            busy = false;
            resetPageWork();
            sourceCanvas = canvas;
            showAdjust(false);
        }).catch(function (err) {
            busy = false;
            setStatus((err && err.message) || 'This photo could not be read. Use a JPEG or PNG.');
        });
    }

    function showAdjust(restore) {
        var token = ++viewToken;
        destroyEditor();
        showingResult = false;
        clearBody();
        setTitle('Adjust');
        setStatus(restore
            ? 'Drag the corners. Flip and rotate, then Apply.'
            : 'Looking for the page…');
        var host = el('div', { class: 'temper-doc-scan-editor' });
        bodyEl.appendChild(host);

        var rotateLabel = el('span', { id: 'temperDocScanRotateLabel', text: 'Rotate ' + formatDegrees(rotateDeg) });
        var rotate = el('input', {
            type: 'range',
            id: 'temperDocScanRotate',
            min: '-180',
            max: '180',
            step: '0.1',
            value: String(rotateDeg),
            'aria-label': 'Rotate'
        });
        rotate.addEventListener('input', function () {
            rotateDeg = Number(rotate.value) || 0;
            rotateLabel.textContent = 'Rotate ' + formatDegrees(rotateDeg);
        });
        bodyEl.appendChild(el('div', { class: 'temper-doc-scan-controls' }, [
            el('div', { class: 'temper-doc-scan-toggles' }, [
                el('button', {
                    type: 'button',
                    class: flipH ? 'is-on' : '',
                    text: 'Flip horizontal',
                    'aria-pressed': flipH ? 'true' : 'false',
                    onclick: function (event) {
                        flipH = !flipH;
                        event.currentTarget.className = flipH ? 'is-on' : '';
                        event.currentTarget.setAttribute('aria-pressed', flipH ? 'true' : 'false');
                    }
                }),
                el('button', {
                    type: 'button',
                    class: flipV ? 'is-on' : '',
                    text: 'Flip vertical',
                    'aria-pressed': flipV ? 'true' : 'false',
                    onclick: function (event) {
                        flipV = !flipV;
                        event.currentTarget.className = flipV ? 'is-on' : '';
                        event.currentTarget.setAttribute('aria-pressed', flipV ? 'true' : 'false');
                    }
                })
            ]),
            el('label', {}, [rotateLabel, rotate])
        ]));
        bodyEl.appendChild(el('div', { class: 'temper-doc-scan-foot' }, [
            el('button', { type: 'button', text: 'Retake', onclick: function () { if (!busy) showCapture(); } }),
            el('button', { type: 'button', text: 'Full image', onclick: function () { useFullImage(); } }),
            el('button', { type: 'button', 'data-act': 'apply', text: 'Apply', onclick: function () { onApply(); } }),
            el('button', { type: 'button', class: 'primary', 'data-act': 'adjust-next', text: 'Next', onclick: function () { onAdjustNext(); } })
        ]));

        ensureScanic().then(function (scanic) {
            if (restore && lastCorners) {
                if (token !== viewToken || !sourceCanvas) return;
                mountEditor(scanic, host, lastCorners);
                setStatus('Drag the corners. Flip and rotate, then Apply.');
                return;
            }
            return scanic.scanDocument(sourceCanvas, { mode: 'detect' }).then(function (result) {
                if (token !== viewToken || !sourceCanvas) return;
                var corners = (result && result.success && result.corners) ? result.corners : null;
                lastCorners = corners;
                mountEditor(scanic, host, corners);
                setStatus(corners
                    ? 'Drag the corners to the page edges. Flip and rotate, then Apply.'
                    : 'No page outline found. Drag the corners to crop the page.');
            });
        }).catch(function (err) {
            if (token !== viewToken) return;
            setStatus((err && err.message) || 'Page detection could not be loaded.');
        });
    }

    function mountEditor(scanic, host, corners) {
        destroyEditor();
        editor = scanic.createCornerEditor({
            container: host,
            image: sourceCanvas,
            corners: corners || undefined,
            toolbar: { enabled: false },
            magnifier: { zoom: 2, size: 110 },
            nudges: { enabled: true, steps: [1, 10] }
        });
    }

    function useFullImage() {
        if (!editor || !sourceCanvas || typeof editor.setCorners !== 'function') return;
        var corners = fullImageCorners(sourceCanvas.width, sourceCanvas.height);
        editor.setCorners(corners);
        lastCorners = corners;
        setStatus('Corners cover the photo. Drag any corner to crop.');
    }

    function runAdjust() {
        if (busy) return Promise.reject(new Error('Wait for the page outline.'));
        if (!sourceCanvas) return Promise.reject(new Error('Retake the photo.'));
        var corners = currentCorners();
        if (!corners) return Promise.reject(new Error('Wait for the page outline, or retake the photo.'));
        readRotateControl();
        var token = viewToken;
        busy = true;
        setStatus('Applying…');
        return ensureScanic().then(function (scanic) {
            return scanic.extractDocument(sourceCanvas, corners, { output: 'canvas' });
        }).then(function (result) {
            if (token !== viewToken) {
                busy = false;
                return null;
            }
            var output = result && result.output;
            if (!result || result.success === false || !output || !output.width) {
                throw new Error('Could not crop that page. Move the corners and try again.');
            }
            lastCorners = corners;
            var canvas = scaleCanvas(output);
            canvas = flipCanvas(canvas, flipH, flipV);
            canvas = rotateCanvas(canvas, rotateDeg);
            canvas = scaleCanvas(canvas);
            adjustedCanvas = canvas;
            showingResult = true;
            busy = false;
            return canvas;
        }).catch(function (err) {
            busy = false;
            throw err;
        });
    }

    function onApply() {
        if (busy) return;
        runAdjust().then(function (canvas) {
            if (canvas) showAdjustResult();
        }).catch(function (err) {
            setStatus((err && err.message) || 'Could not apply that adjustment.');
        });
    }

    function onAdjustNext() {
        if (busy) return;
        if (showingResult && adjustedCanvas) {
            showEnhance();
            return;
        }
        runAdjust().then(function (canvas) {
            if (canvas) showEnhance();
        }).catch(function (err) {
            setStatus((err && err.message) || 'Could not apply that adjustment.');
        });
    }

    function showAdjustResult() {
        viewToken += 1;
        destroyEditor();
        showingResult = true;
        clearBody();
        setTitle('Adjust');
        setStatus('Adjustments are applied. Crop again to move the corners, or continue.');
        var img = el('img', { class: 'temper-doc-scan-preview', alt: 'Adjusted page' });
        try {
            img.src = adjustedCanvas.toDataURL('image/jpeg', 0.8);
        } catch (e) {
            img.alt = 'Adjusted page';
        }
        bodyEl.appendChild(el('div', { class: 'temper-doc-scan-preview-wrap' }, [img]));
        bodyEl.appendChild(el('div', { class: 'temper-doc-scan-foot' }, [
            el('button', { type: 'button', 'data-act': 'crop-again', text: 'Crop again', onclick: function () { if (!busy) showAdjust(true); } }),
            el('button', { type: 'button', class: 'primary', 'data-act': 'adjust-next', text: 'Next', onclick: function () { onAdjustNext(); } })
        ]));
        focusPrimary();
    }

    function paintEnhance() {
        var host = document.getElementById('temperDocScanEnhancePreview');
        if (!host || !adjustedCanvas) return;
        host.textContent = '';
        var view = enhanceCanvas(adjustedCanvas, grayPct, contrastPct);
        view.className = 'temper-doc-scan-preview';
        host.appendChild(view);
    }

    function scheduleEnhancePaint() {
        if (enhanceFrame) return;
        enhanceFrame = requestAnimationFrame(function () {
            enhanceFrame = 0;
            paintEnhance();
        });
    }

    function showEnhance() {
        viewToken += 1;
        destroyEditor();
        showingResult = true;
        clearBody();
        setTitle('Enhance');
        setStatus('Adjust grayscale and contrast. Next adds this page to the document.');
        bodyEl.appendChild(el('div', { id: 'temperDocScanEnhancePreview', class: 'temper-doc-scan-preview-wrap' }));
        var grayLabel = el('span', { id: 'temperDocScanGrayLabel', text: 'Grayscale ' + Math.round(grayPct) + '%' });
        var gray = el('input', {
            type: 'range',
            id: 'temperDocScanGray',
            min: '0',
            max: '100',
            step: '1',
            value: String(grayPct),
            'aria-label': 'Grayscale'
        });
        gray.addEventListener('input', function () {
            grayPct = Number(gray.value) || 0;
            grayLabel.textContent = 'Grayscale ' + Math.round(grayPct) + '%';
            scheduleEnhancePaint();
        });
        var contrastLabel = el('span', { id: 'temperDocScanContrastLabel', text: 'Contrast ' + Math.round(contrastPct) + '%' });
        var contrast = el('input', {
            type: 'range',
            id: 'temperDocScanContrast',
            min: '50',
            max: '200',
            step: '1',
            value: String(contrastPct),
            'aria-label': 'Contrast'
        });
        contrast.addEventListener('input', function () {
            contrastPct = Number(contrast.value) || 100;
            contrastLabel.textContent = 'Contrast ' + Math.round(contrastPct) + '%';
            scheduleEnhancePaint();
        });
        bodyEl.appendChild(el('div', { class: 'temper-doc-scan-controls' }, [
            el('label', {}, [grayLabel, gray]),
            el('label', {}, [contrastLabel, contrast])
        ]));
        bodyEl.appendChild(el('div', { class: 'temper-doc-scan-foot' }, [
            el('button', { type: 'button', text: 'Back', onclick: function () { if (!busy) showAdjustResult(); } }),
            el('button', { type: 'button', class: 'primary', 'data-act': 'enhance-next', text: 'Next', onclick: function () { acceptEnhancedPage(); } })
        ]));
        paintEnhance();
        focusPrimary();
    }

    function acceptEnhancedPage() {
        if (busy || !adjustedCanvas) return;
        if (pages.length >= MAX_PAGES) {
            setStatus('This scan already has ' + MAX_PAGES + ' pages.');
            return;
        }
        busy = true;
        setStatus('Adding page…');
        var finalCanvas;
        try {
            finalCanvas = enhanceCanvas(adjustedCanvas, grayPct, contrastPct);
        } catch (err) {
            busy = false;
            setStatus((err && err.message) || 'Could not enhance that page.');
            return;
        }
        canvasToJpeg(finalCanvas).then(function (jpeg) {
            var thumb = document.createElement('canvas');
            var tw = 160;
            var th = Math.max(1, Math.round(finalCanvas.height * (tw / finalCanvas.width)));
            thumb.width = tw;
            thumb.height = th;
            var tctx = thumb.getContext('2d');
            if (tctx) tctx.drawImage(finalCanvas, 0, 0, tw, th);
            var thumbUrl = '';
            try { thumbUrl = thumb.toDataURL('image/jpeg', 0.7); } catch (e) { thumbUrl = ''; }
            var fullUrl = '';
            try {
                fullUrl = URL.createObjectURL(new Blob([jpeg], { type: 'image/jpeg' }));
            } catch (e2) {
                fullUrl = thumbUrl;
            }
            pages.push({
                jpeg: jpeg,
                width: finalCanvas.width,
                height: finalCanvas.height,
                thumbUrl: thumbUrl,
                fullUrl: fullUrl
            });
            busy = false;
            resetPageWork();
            showBrowser();
        }).catch(function (err) {
            busy = false;
            setStatus((err && err.message) || 'Could not add that page.');
        });
    }

    function movePage(index, dir) {
        var next = index + dir;
        if (next < 0 || next >= pages.length) return;
        var item = pages[index];
        pages.splice(index, 1);
        pages.splice(next, 0, item);
        showBrowser();
    }

    function deletePage(index) {
        var page = pages[index];
        if (!page) return;
        releasePage(page);
        pages.splice(index, 1);
        if (!pages.length) showCapture();
        else showBrowser();
    }

    function showBrowser() {
        viewToken += 1;
        destroyEditor();
        showingResult = false;
        clearBody();
        setTitle('Pages');
        setStatus(pages.length
            ? 'Finish names the document and builds one PDF.'
            : 'No pages yet.');
        var list = el('div', { class: 'temper-doc-scan-browser' });
        pages.forEach(function (page, index) {
            var img = el('img', { alt: 'Page ' + (index + 1) });
            if (page.thumbUrl) img.src = page.thumbUrl;
            list.appendChild(el('div', { class: 'temper-doc-scan-page', 'data-page-index': String(index) }, [
                el('button', {
                    type: 'button',
                    text: '',
                    'aria-label': 'View page ' + (index + 1),
                    onclick: function () { showPageView(index); }
                }),
                el('div', { class: 'temper-doc-scan-page-main' }, [
                    el('div', { text: 'Page ' + (index + 1) }),
                    el('div', { class: 'temper-doc-scan-page-actions' }, [
                        el('button', { type: 'button', text: 'View', onclick: function () { showPageView(index); } }),
                        el('button', {
                            type: 'button',
                            text: 'Earlier',
                            disabled: index === 0 ? 'disabled' : null,
                            onclick: function () { movePage(index, -1); }
                        }),
                        el('button', {
                            type: 'button',
                            text: 'Later',
                            disabled: index === pages.length - 1 ? 'disabled' : null,
                            onclick: function () { movePage(index, 1); }
                        }),
                        el('button', { type: 'button', text: 'Delete', onclick: function () { deletePage(index); } })
                    ])
                ])
            ]));
            var thumbBtn = list.lastChild.querySelector('button');
            if (thumbBtn && img) thumbBtn.appendChild(img);
        });
        bodyEl.appendChild(list);
        bodyEl.appendChild(el('div', { class: 'temper-doc-scan-foot' }, [
            el('button', {
                type: 'button',
                text: 'Add page',
                disabled: pages.length >= MAX_PAGES ? 'disabled' : null,
                onclick: function () { showCapture(); }
            }),
            el('button', {
                type: 'button',
                class: 'primary',
                'data-act': 'finish',
                text: 'Finish',
                disabled: pages.length ? null : 'disabled',
                onclick: function () { showName(); }
            })
        ]));
        focusPrimary();
    }

    function showPageView(index) {
        var page = pages[index];
        if (!page) {
            showBrowser();
            return;
        }
        viewToken += 1;
        clearBody();
        setTitle('Page ' + (index + 1));
        setStatus('Page ' + (index + 1) + ' of ' + pages.length + '.');
        var img = el('img', { class: 'temper-doc-scan-preview', alt: 'Page ' + (index + 1) });
        img.src = page.fullUrl || page.thumbUrl || '';
        bodyEl.appendChild(el('div', { class: 'temper-doc-scan-preview-wrap' }, [img]));
        bodyEl.appendChild(el('div', { class: 'temper-doc-scan-foot' }, [
            el('button', { type: 'button', text: 'Back', onclick: function () { showBrowser(); } }),
            el('button', {
                type: 'button',
                text: 'Earlier',
                disabled: index === 0 ? 'disabled' : null,
                onclick: function () {
                    movePage(index, -1);
                    showPageView(Math.max(0, index - 1));
                }
            }),
            el('button', {
                type: 'button',
                text: 'Later',
                disabled: index === pages.length - 1 ? 'disabled' : null,
                onclick: function () {
                    movePage(index, 1);
                    showPageView(Math.min(pages.length - 1, index + 1));
                }
            }),
            el('button', { type: 'button', text: 'Delete', onclick: function () { deletePage(index); } })
        ]));
    }

    function showName() {
        if (!pages.length) return;
        viewToken += 1;
        clearBody();
        setTitle('Finish');
        setStatus('Name this document. Finish builds one PDF.');
        var input = el('input', {
            type: 'text',
            id: 'temperDocScanName',
            value: defaultDocumentName(),
            maxlength: '80',
            autocomplete: 'off',
            'aria-label': 'Document name'
        });
        bodyEl.appendChild(el('div', { class: 'temper-doc-scan-name' }, [
            el('label', { text: 'Document name' }),
            input
        ]));
        bodyEl.appendChild(el('div', { class: 'temper-doc-scan-foot' }, [
            el('button', { type: 'button', text: 'Back', onclick: function () { if (!busy) showBrowser(); } }),
            el('button', {
                type: 'button',
                class: 'primary',
                'data-act': 'finish-build',
                text: 'Finish',
                onclick: function () { finish(); }
            })
        ]));
        if (input.focus) input.focus();
        if (input.select) input.select();
    }

    function finish() {
        if (busy || !pages.length || !options || typeof options.onComplete !== 'function') return;
        var nameInput = document.getElementById('temperDocScanName');
        var name = safePdfName(nameInput ? nameInput.value : '');
        busy = true;
        setStatus('Building PDF…');
        var bytes;
        try {
            bytes = buildJpegPdf(pages.map(function (page) {
                return { jpeg: page.jpeg, width: page.width, height: page.height };
            }));
        } catch (err) {
            busy = false;
            setStatus((err && err.message) || 'Could not build the PDF.');
            return;
        }
        var file = new File([bytes], name, { type: 'application/pdf', lastModified: Date.now() });
        try {
            var result = options.onComplete(file);
            close(true);
            if (result && typeof result.then === 'function') {
                result.catch(function () { /* caller reports upload errors */ });
            }
        } catch (err) {
            busy = false;
            setStatus((err && err.message) || 'Could not hand off the scan.');
        }
    }

    function open(nextOptions) {
        if (overlay) return;
        options = nextOptions || {};
        pages = [];
        busy = false;
        resetPageWork();
        injectStyles();
        overlay = el('div', { class: 'temper-doc-scan', role: 'dialog', 'aria-modal': 'true', 'aria-label': 'Scan document' });
        titleEl = el('strong', { text: 'Scan' });
        statusEl = el('div', { class: 'temper-doc-scan-status' });
        bodyEl = el('div', { class: 'temper-doc-scan-body' });
        overlay.appendChild(el('div', { class: 'temper-doc-scan-bar' }, [
            titleEl,
            el('button', { type: 'button', text: 'Close', 'data-act': 'close', onclick: function () { close(false); } })
        ]));
        overlay.appendChild(statusEl);
        overlay.appendChild(bodyEl);
        document.body.appendChild(overlay);
        keyHandler = function (event) {
            if (!overlay) return;
            if (event.key === 'Escape') {
                event.preventDefault();
                event.stopPropagation();
                close(false);
            }
        };
        focusHandler = function (event) {
            if (!overlay) return;
            if (overlay.contains(event.target)) {
                event.stopPropagation();
                return;
            }
            event.stopPropagation();
            var btn = overlay.querySelector('button');
            if (btn && btn.focus) btn.focus();
        };
        document.addEventListener('keydown', keyHandler, true);
        document.addEventListener('focusin', focusHandler, true);
        showCapture();
    }

    window.TemperDocScan = {
        open: open,
        close: function () { close(true); },
        buildJpegPdf: buildJpegPdf
    };
})();
