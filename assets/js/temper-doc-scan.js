/**
 * Reusable document scan step.
 *
 * TemperDocScan.open({ onComplete: function (file) {} })
 * The caller receives one named PDF File and uploads it through its own path.
 * A normal file-picker upload never enters this step and is not converted to PDF.
 *
 * Flow: live camera in a secure context (corner overlay, shutter, optional auto-capture).
 *   On plain HTTP, a camera-app photo via capture="environment" — getUserMedia is not called.
 *   Then one review screen (drag corners, free rotate, Original / Grayscale / Black & white,
 *      thumbnails, add, retake, delete, reorder)
 *   → document name → one PDF.
 * Page detection uses Scanic (assets/vendor/scanic). Corners can always be dragged.
 */
(function () {
    if (window.TemperDocScan && typeof window.TemperDocScan.open === 'function') {
        return;
    }

    var MAX_EDGE = 2000;
    var MAX_PAGES = 12;
    var JPEG_QUALITY = 0.82;
    var DETECT_EDGE = 640;
    var SCANIC_URL = 'assets/vendor/scanic/scanic.js';
    var FILTERS = [
        { id: 'original', label: 'Original' },
        { id: 'gray', label: 'Grayscale' },
        { id: 'bw', label: 'Black & white' }
    ];

    var overlay = null;
    var bodyEl = null;
    var statusEl = null;
    var titleEl = null;
    var toolsEl = null;
    var options = null;
    var pages = [];
    var reviewIndex = 0;
    var retakeIndex = null;
    var editor = null;
    var keyHandler = null;
    var focusHandler = null;
    var resizeHandler = null;
    var busy = false;
    var stylesInjected = false;
    var viewToken = 0;
    var cameraToken = 0;
    var mode = '';
    var cameraStream = null;
    var videoEl = null;
    var overlayCanvas = null;
    var liveCorners = null;
    var liveMeta = null;
    var lastLiveCorners = null;
    var stableCount = 0;
    var autoCapture = false;
    var autoArmed = true;
    var detectTimer = 0;
    var detecting = false;
    var renderTimer = 0;

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
            '.temper-doc-scan-tools{display:flex;gap:.5rem;margin-left:auto;flex-wrap:wrap;}',
            '.temper-doc-scan-body{flex:1 1 auto;min-height:0;display:flex;flex-direction:column;overflow:hidden;}',
            '.temper-doc-scan-stage{position:relative;flex:1 1 auto;min-height:220px;background:#000;overflow:hidden;}',
            '.temper-doc-scan-stage video{position:absolute;inset:0;width:100%;height:100%;object-fit:contain;background:#000;}',
            '.temper-doc-scan-overlay{position:absolute;inset:0;width:100%;height:100%;pointer-events:none;}',
            '.temper-doc-scan-editor{position:relative;flex:1 1 auto;min-height:180px;background:#000;overflow:hidden;touch-action:none;}',
            '.temper-doc-scan-status{padding:.45rem .75rem;color:#d5dbe2;font-size:.92rem;}',
            '.temper-doc-scan-capture,.temper-doc-scan-name{display:flex;flex-direction:column;gap:.75rem;padding:1.25rem .9rem;}',
            '.temper-doc-scan button{min-height:44px;border-radius:.4rem;border:1px solid #8ea0b5;',
            'background:#243040;color:#fff;padding:.4rem .8rem;font:inherit;}',
            '.temper-doc-scan button.primary{background:#0d6efd;border-color:#0d6efd;}',
            '.temper-doc-scan button.is-on{background:#0d6efd;border-color:#0d6efd;}',
            '.temper-doc-scan button:disabled{opacity:.55;}',
            '.temper-doc-scan-cam-foot{justify-content:center;}',
            '.temper-doc-scan button.temper-doc-scan-shutter{width:72px;height:72px;min-height:72px;padding:0;',
            'border-radius:50%;border:4px solid #fff;background:#f4f7fb;color:#121416;}',
            '.temper-doc-scan button.temper-doc-scan-shutter:active{background:#0d6efd;color:#fff;}',
            '.temper-doc-scan-review-tools{flex:0 1 auto;max-height:46%;overflow:auto;padding:.35rem .75rem .5rem;}',
            '.temper-doc-scan-result{display:block;max-width:100%;max-height:120px;margin:0 auto .35rem;object-fit:contain;background:#000;}',
            '.temper-doc-scan-controls{display:flex;flex-direction:column;gap:.45rem;}',
            '.temper-doc-scan-controls label{display:flex;flex-direction:column;gap:.15rem;font-size:.92rem;}',
            '.temper-doc-scan-controls input[type=range]{width:100%;height:44px;accent-color:#0d6efd;}',
            '.temper-doc-scan-chips{display:flex;gap:.4rem;flex-wrap:wrap;}',
            '.temper-doc-scan-thumbs{display:flex;gap:.4rem;overflow-x:auto;padding:.45rem 0 .1rem;}',
            '.temper-doc-scan-thumbs button{padding:.15rem;min-height:0;width:3.5rem;height:4.6rem;',
            'display:flex;align-items:center;justify-content:center;background:#000;}',
            '.temper-doc-scan-thumbs img{max-width:100%;max-height:100%;object-fit:contain;}',
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

    function setBar(buttons) {
        if (!toolsEl) return;
        toolsEl.textContent = '';
        (buttons || []).forEach(function (button) {
            if (button) toolsEl.appendChild(button);
        });
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
        releaseUrl(page.fullUrl);
        page.fullUrl = '';
    }

    function releasePages() {
        pages.forEach(releasePage);
        pages = [];
        reviewIndex = 0;
        retakeIndex = null;
    }

    function stopDetect() {
        if (detectTimer) {
            clearTimeout(detectTimer);
            detectTimer = 0;
        }
        detecting = false;
    }

    function stopCamera() {
        stopDetect();
        liveCorners = null;
        liveMeta = null;
        lastLiveCorners = null;
        stableCount = 0;
        if (cameraStream) {
            cameraStream.getTracks().forEach(function (track) {
                try { track.stop(); } catch (e) { /* already stopped */ }
            });
            cameraStream = null;
        }
        if (videoEl) {
            try { videoEl.srcObject = null; } catch (e2) { /* ignore */ }
        }
        videoEl = null;
        overlayCanvas = null;
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
        if (resizeHandler) {
            window.removeEventListener('resize', resizeHandler);
            resizeHandler = null;
        }
    }

    function close(force) {
        if (!overlay) return;
        if (!force && pages.length) {
            var ask = (typeof window.confirm === 'function') ? window.confirm : function () { return true; };
            if (!ask('Cancel and discard pages that are not saved?')) return;
        }
        viewToken += 1;
        cameraToken += 1;
        if (renderTimer) {
            clearTimeout(renderTimer);
            renderTimer = 0;
        }
        destroyEditor();
        stopCamera();
        releasePages();
        detachGuards();
        if (overlay.parentNode) overlay.parentNode.removeChild(overlay);
        overlay = null;
        bodyEl = null;
        statusEl = null;
        titleEl = null;
        toolsEl = null;
        options = null;
        busy = false;
        mode = '';
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
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, w, h);
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
        return n | 0;
    }

    function applyFilter(src, filterName) {
        if (!src || filterName === 'original' || !filterName) return src;
        var canvas = document.createElement('canvas');
        canvas.width = src.width;
        canvas.height = src.height;
        var ctx = canvas.getContext('2d');
        if (!ctx) return src;
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        ctx.drawImage(src, 0, 0);
        var image = ctx.getImageData(0, 0, canvas.width, canvas.height);
        var data = image.data;
        var bw = filterName === 'bw';
        var i;
        var y;
        for (i = 0; i < data.length; i += 4) {
            y = (0.299 * data[i]) + (0.587 * data[i + 1]) + (0.114 * data[i + 2]);
            if (bw) {
                y = ((y - 128) * 1.35) + 128;
                y = y >= 160 ? 255 : 0;
            }
            data[i] = clampByte(y);
            data[i + 1] = clampByte(y);
            data[i + 2] = clampByte(y);
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

    function cornerDelta(a, b) {
        if (!a || !b) return Infinity;
        var keys = ['topLeft', 'topRight', 'bottomRight', 'bottomLeft'];
        var max = 0;
        var i;
        for (i = 0; i < keys.length; i++) {
            var pa = a[keys[i]];
            var pb = b[keys[i]];
            if (!pa || !pb) return Infinity;
            var dist = Math.hypot((pa.x || 0) - (pb.x || 0), (pa.y || 0) - (pb.y || 0));
            if (dist > max) max = dist;
        }
        return max;
    }

    function blankPage(canvas) {
        return {
            sourceCanvas: canvas,
            corners: null,
            rotateDeg: 0,
            filter: 'original',
            jpeg: null,
            width: canvas.width,
            height: canvas.height,
            thumbUrl: '',
            fullUrl: ''
        };
    }

    function currentPage() {
        return pages[reviewIndex] || null;
    }

    function cancelButton() {
        return el('button', {
            type: 'button',
            text: 'Cancel',
            'data-act': 'cancel',
            onclick: function () { close(false); }
        });
    }

    function cameraBarButtons() {
        var buttons = [];
        if (pages.length) {
            buttons.push(el('button', {
                type: 'button',
                text: 'Back',
                'data-act': 'back',
                onclick: function () { if (!busy) backToReview(); }
            }));
        }
        buttons.push(cancelButton());
        return buttons;
    }

    function reviewBarButtons() {
        return [
            el('button', {
                type: 'button',
                class: 'primary',
                text: 'Save',
                'data-act': 'save',
                onclick: function () { onSave(); }
            }),
            cancelButton()
        ];
    }

    function showCamera() {
        var token = ++cameraToken;
        viewToken += 1;
        if (renderTimer) {
            clearTimeout(renderTimer);
            renderTimer = 0;
        }
        destroyEditor();
        stopCamera();
        mode = 'camera';
        clearBody();
        if (bodyEl) bodyEl.setAttribute('data-screen', 'camera');
        setTitle('Camera');
        setBar(cameraBarButtons());

        // Plain HTTP is not a secure context, so getUserMedia is unavailable.
        // enumerateDevices() is also empty until permission is granted. Neither
        // means the phone has no camera. The camera app opens from a file input.
        if (window.isSecureContext !== true) {
            showFileCapture('Take a photo with the camera, or choose one.');
            return;
        }

        setStatus('Opening camera…');

        var stage = el('div', { class: 'temper-doc-scan-stage' });
        videoEl = el('video', { autoplay: true, muted: true, playsinline: true });
        videoEl.autoplay = true;
        videoEl.muted = true;
        videoEl.playsInline = true;
        overlayCanvas = el('canvas', { class: 'temper-doc-scan-overlay', 'aria-hidden': 'true' });
        stage.appendChild(videoEl);
        stage.appendChild(overlayCanvas);
        bodyEl.appendChild(stage);

        var library = photoFileInput(false);
        var autoBtn = el('button', {
            type: 'button',
            text: 'Auto',
            'data-act': 'auto',
            class: autoCapture ? 'is-on' : '',
            'aria-pressed': autoCapture ? 'true' : 'false',
            'aria-label': 'Auto-capture when the page is steady',
            onclick: function (event) { toggleAuto(event.currentTarget); }
        });
        var shutter = el('button', {
            type: 'button',
            class: 'temper-doc-scan-shutter',
            'data-act': 'shutter',
            'aria-label': 'Shutter',
            onclick: function () { onShutter(); }
        });
        bodyEl.appendChild(el('div', { class: 'temper-doc-scan-foot temper-doc-scan-cam-foot' }, [
            autoBtn,
            shutter,
            el('button', {
                type: 'button',
                text: 'Choose a photo',
                'data-act': 'choose-photo',
                onclick: function () { library.click(); }
            })
        ]));
        bodyEl.appendChild(library);
        bindResize();
        startCamera(token);
    }

    function bindResize() {
        if (resizeHandler) return;
        resizeHandler = function () { resizeOverlay(); };
        window.addEventListener('resize', resizeHandler);
    }

    function toggleAuto(button) {
        autoCapture = !autoCapture;
        stableCount = 0;
        autoArmed = true;
        if (button) {
            button.className = autoCapture ? 'is-on' : '';
            button.setAttribute('aria-pressed', autoCapture ? 'true' : 'false');
        }
        if (!liveCorners) {
            setStatus(autoCapture
                ? 'Auto is on. A steady page is taken, or use the shutter.'
                : 'Shutter takes the picture.');
        }
    }

    function photoFileInput(capture) {
        var attrs = {
            type: 'file',
            accept: 'image/*',
            'data-act': capture ? 'camera' : 'photo-input',
            style: 'display:none'
        };
        if (capture) attrs.capture = 'environment';
        var input = el('input', attrs);
        input.addEventListener('change', function () { onPhotoFile(input); });
        return input;
    }

    function startCamera(token) {
        var media = navigator.mediaDevices;
        if (!media || typeof media.getUserMedia !== 'function') {
            showFileCapture('Take a photo with the camera, or choose one.');
            return;
        }
        media.getUserMedia({
            audio: false,
            video: {
                facingMode: { ideal: 'environment' },
                width: { ideal: 1920 },
                height: { ideal: 1080 }
            }
        }).then(function (stream) {
            if (token !== cameraToken || mode !== 'camera' || !videoEl) {
                stream.getTracks().forEach(function (track) { try { track.stop(); } catch (e) { /* ignore */ } });
                return;
            }
            cameraStream = stream;
            videoEl.srcObject = stream;
            var play = videoEl.play();
            if (play && typeof play.catch === 'function') play.catch(function () { /* autoplay can reject */ });
            function armDetect() {
                if (token !== cameraToken || mode !== 'camera' || !videoEl || videoEl._temperArmed) return;
                if (!videoEl.videoWidth) return;
                videoEl._temperArmed = true;
                resizeOverlay();
                setStatus(autoCapture
                    ? 'Auto is on. A steady page is taken, or use the shutter.'
                    : 'Shutter takes the picture.');
                scheduleDetect(token);
            }
            videoEl.addEventListener('loadeddata', armDetect);
            videoEl.addEventListener('loadedmetadata', armDetect);
            var tries = 0;
            function poke() {
                if (token !== cameraToken || mode !== 'camera' || !videoEl) return;
                armDetect();
                if (!videoEl._temperArmed && tries < 25) {
                    tries += 1;
                    setTimeout(poke, 200);
                }
            }
            poke();
        }).catch(function (err) {
            if (token !== cameraToken || mode !== 'camera') return;
            var name = err && err.name ? String(err.name) : '';
            if (name === 'NotAllowedError' || name === 'PermissionDeniedError') {
                showFileCapture('Camera permission was denied.');
                return;
            }
            showFileCapture('The camera did not open. Take a photo, or choose one.');
        });
    }

    function showFileCapture(message) {
        stopCamera();
        if (mode !== 'camera' || !bodyEl) return;
        clearBody();
        bodyEl.setAttribute('data-screen', 'camera');
        setTitle('Camera');
        setBar(cameraBarButtons());
        setStatus(message);
        var camera = photoFileInput(true);
        var library = photoFileInput(false);
        bodyEl.appendChild(el('div', { class: 'temper-doc-scan-capture' }, [
            el('p', { text: message }),
            el('button', {
                type: 'button',
                class: 'primary',
                text: 'Take photo',
                'data-act': 'take-photo',
                onclick: function () { camera.click(); }
            }),
            el('button', {
                type: 'button',
                text: 'Choose a photo',
                'data-act': 'choose-photo',
                onclick: function () { library.click(); }
            }),
            camera,
            library
        ]));
    }

    function onPhotoFile(input) {
        var file = input.files && input.files[0];
        input.value = '';
        if (!file || busy) return;
        if (file.type && file.type.indexOf('image/') !== 0) {
            setStatus('Choose a photo.');
            return;
        }
        busy = true;
        setStatus('Reading photo…');
        fileToCanvas(file).then(function (canvas) {
            busy = false;
            acceptCapture(canvas);
        }).catch(function (err) {
            busy = false;
            setStatus((err && err.message) || 'This photo could not be read. Use a JPEG or PNG.');
        });
    }

    function scheduleDetect(token) {
        if (detectTimer) clearTimeout(detectTimer);
        detectTimer = setTimeout(function () { runDetect(token); }, 450);
    }

    function runDetect(token) {
        detectTimer = 0;
        if (token !== cameraToken || mode !== 'camera' || !videoEl || detecting) return;
        if ((videoEl.readyState || 0) < 2 || !videoEl.videoWidth) {
            scheduleDetect(token);
            return;
        }
        var frame;
        try {
            frame = grabAnalysis(videoEl);
        } catch (e) {
            scheduleDetect(token);
            return;
        }
        detecting = true;
        ensureScanic().then(function (scanic) {
            return scanic.scanDocument(frame.canvas, { mode: 'detect' });
        }).then(function (result) {
            detecting = false;
            if (token !== cameraToken || mode !== 'camera') return;
            var corners = (result && result.success && result.corners) ? result.corners : null;
            if (corners) {
                liveCorners = corners;
                liveMeta = { aw: frame.aw, ah: frame.ah };
                resizeOverlay();
                noteSteady(corners);
            } else {
                liveCorners = null;
                liveMeta = null;
                lastLiveCorners = null;
                stableCount = 0;
                autoArmed = true;
                clearOverlay();
                setStatus(autoCapture
                    ? 'Auto is on. Point at a page, or use the shutter.'
                    : 'Shutter takes the picture.');
            }
            scheduleDetect(token);
        }).catch(function () {
            detecting = false;
            if (token !== cameraToken || mode !== 'camera') return;
            setStatus('Page outline is unavailable. Shutter still takes the picture.');
            scheduleDetect(token);
        });
    }

    function grabAnalysis(video) {
        var vw = video.videoWidth;
        var vh = video.videoHeight;
        var long = Math.max(vw, vh);
        var scale = long > DETECT_EDGE ? DETECT_EDGE / long : 1;
        var aw = Math.max(1, Math.round(vw * scale));
        var ah = Math.max(1, Math.round(vh * scale));
        var canvas = document.createElement('canvas');
        canvas.width = aw;
        canvas.height = ah;
        var ctx = canvas.getContext('2d', { willReadFrequently: true });
        if (!ctx) throw new Error('No canvas.');
        ctx.drawImage(video, 0, 0, aw, ah);
        return { canvas: canvas, aw: aw, ah: ah };
    }

    function resizeOverlay() {
        if (!overlayCanvas || !overlayCanvas.parentNode) return;
        var stage = overlayCanvas.parentNode;
        var w = stage.clientWidth || 0;
        var h = stage.clientHeight || 0;
        if (w < 2 || h < 2) return;
        var dpr = window.devicePixelRatio || 1;
        overlayCanvas.width = Math.max(1, Math.round(w * dpr));
        overlayCanvas.height = Math.max(1, Math.round(h * dpr));
        drawOverlay();
    }

    function clearOverlay() {
        if (!overlayCanvas) return;
        var ctx = overlayCanvas.getContext('2d');
        if (ctx) ctx.clearRect(0, 0, overlayCanvas.width, overlayCanvas.height);
    }

    function drawOverlay() {
        if (!overlayCanvas) return;
        var ctx = overlayCanvas.getContext('2d');
        if (!ctx) return;
        var dpr = window.devicePixelRatio || 1;
        var w = overlayCanvas.width / dpr;
        var h = overlayCanvas.height / dpr;
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        ctx.clearRect(0, 0, w, h);
        if (!liveCorners || !liveMeta || !videoEl || !videoEl.videoWidth) return;
        var vw = videoEl.videoWidth;
        var vh = videoEl.videoHeight;
        var scale = Math.min(w / vw, h / vh);
        var dw = vw * scale;
        var dh = vh * scale;
        var ox = (w - dw) / 2;
        var oy = (h - dh) / 2;
        function pt(p) {
            return {
                x: ox + ((p.x || 0) / liveMeta.aw) * dw,
                y: oy + ((p.y || 0) / liveMeta.ah) * dh
            };
        }
        var tl = pt(liveCorners.topLeft || {});
        var tr = pt(liveCorners.topRight || {});
        var br = pt(liveCorners.bottomRight || {});
        var bl = pt(liveCorners.bottomLeft || {});
        ctx.beginPath();
        ctx.moveTo(tl.x, tl.y);
        ctx.lineTo(tr.x, tr.y);
        ctx.lineTo(br.x, br.y);
        ctx.lineTo(bl.x, bl.y);
        ctx.closePath();
        ctx.fillStyle = 'rgba(61, 220, 132, 0.18)';
        ctx.strokeStyle = '#3ddc84';
        ctx.lineWidth = 3;
        ctx.fill();
        ctx.stroke();
        [tl, tr, br, bl].forEach(function (p) {
            ctx.beginPath();
            ctx.arc(p.x, p.y, 7, 0, Math.PI * 2);
            ctx.fillStyle = '#ffffff';
            ctx.fill();
        });
    }

    function noteSteady(corners) {
        if (lastLiveCorners && cornerDelta(lastLiveCorners, corners) < 12) stableCount += 1;
        else stableCount = 0;
        lastLiveCorners = corners;
        if (autoCapture && stableCount >= 3 && autoArmed) {
            setStatus('Holding steady…');
            if (onShutter()) autoArmed = false;
            return;
        }
        setStatus(autoCapture
            ? 'Page found. Hold steady, or use the shutter.'
            : 'Page found. Shutter takes the picture.');
    }

    function onShutter() {
        if (busy || mode !== 'camera' || !videoEl) return false;
        var w = videoEl.videoWidth || 0;
        var h = videoEl.videoHeight || 0;
        if (w < 2 || h < 2) {
            setStatus('Wait for the camera picture.');
            return false;
        }
        var canvas;
        try {
            canvas = drawToCanvas(videoEl, w, h);
        } catch (err) {
            setStatus((err && err.message) || 'Could not take that picture.');
            return false;
        }
        acceptCapture(canvas);
        return true;
    }

    function acceptCapture(canvas) {
        if (!canvas || !canvas.width) return;
        var page = blankPage(canvas);
        if (retakeIndex !== null && pages[retakeIndex]) {
            releasePage(pages[retakeIndex]);
            pages[retakeIndex] = page;
            reviewIndex = retakeIndex;
        } else {
            if (pages.length >= MAX_PAGES) {
                setStatus('This scan already has ' + MAX_PAGES + ' pages.');
                if (pages.length) showReview();
                return;
            }
            pages.push(page);
            reviewIndex = pages.length - 1;
        }
        retakeIndex = null;
        showReview();
    }

    function backToReview() {
        retakeIndex = null;
        if (pages.length) showReview();
        else showCamera();
    }

    function prepareReviewCorners(page) {
        if (page.corners) return Promise.resolve(page.corners);
        return ensureScanic().then(function (scanic) {
            return scanic.scanDocument(page.sourceCanvas, { mode: 'detect' });
        }).then(function (result) {
            page.corners = (result && result.success && result.corners)
                ? result.corners
                : fullImageCorners(page.sourceCanvas.width, page.sourceCanvas.height);
            return page.corners;
        }).catch(function () {
            page.corners = fullImageCorners(page.sourceCanvas.width, page.sourceCanvas.height);
            return page.corners;
        });
    }

    function showReview() {
        var token = ++viewToken;
        cameraToken += 1;
        if (renderTimer) {
            clearTimeout(renderTimer);
            renderTimer = 0;
        }
        destroyEditor();
        stopCamera();
        mode = 'review';
        var page = currentPage();
        if (!page) {
            showCamera();
            return;
        }
        clearBody();
        bodyEl.setAttribute('data-screen', 'review');
        setTitle('Review');
        setBar(reviewBarButtons());
        setStatus('Page ' + (reviewIndex + 1) + ' of ' + pages.length + '. Looking for the page…');

        var host = el('div', { class: 'temper-doc-scan-editor', 'data-act': 'editor' });
        bodyEl.appendChild(host);
        var result = el('img', {
            id: 'temperDocScanResult',
            class: 'temper-doc-scan-result',
            alt: 'Reviewed page'
        });
        if (page.fullUrl) result.src = page.fullUrl;
        var rotateLabel = el('span', {
            id: 'temperDocScanRotateLabel',
            text: 'Rotate ' + formatDegrees(page.rotateDeg)
        });
        var rotate = el('input', {
            type: 'range',
            id: 'temperDocScanRotate',
            min: '-180',
            max: '180',
            step: '0.1',
            value: String(page.rotateDeg || 0),
            'aria-label': 'Rotate'
        });
        rotate.addEventListener('input', function () {
            var current = currentPage();
            if (!current) return;
            current.rotateDeg = Number(rotate.value) || 0;
            rotateLabel.textContent = 'Rotate ' + formatDegrees(current.rotateDeg);
            scheduleRender();
        });
        var chips = el('div', { class: 'temper-doc-scan-chips', role: 'group', 'aria-label': 'Filter' });
        FILTERS.forEach(function (item) {
            var on = page.filter === item.id;
            chips.appendChild(el('button', {
                type: 'button',
                text: item.label,
                class: on ? 'is-on' : '',
                'data-act': 'filter',
                'data-filter': item.id,
                'aria-pressed': on ? 'true' : 'false',
                onclick: function () { setFilter(item.id); }
            }));
        });
        var thumbs = el('div', { class: 'temper-doc-scan-thumbs', 'data-act': 'thumbs' });
        bodyEl.appendChild(el('div', { class: 'temper-doc-scan-review-tools' }, [
            result,
            el('div', { class: 'temper-doc-scan-controls' }, [
                el('label', {}, [rotateLabel, rotate]),
                chips
            ]),
            thumbs
        ]));
        refreshThumbStrip();
        bodyEl.appendChild(el('div', { class: 'temper-doc-scan-foot' }, [
            el('button', { type: 'button', text: 'Retake', 'data-act': 'retake', onclick: function () { onRetake(); } }),
            el('button', { type: 'button', text: 'Delete', 'data-act': 'delete', onclick: function () { deletePage(reviewIndex); } }),
            el('button', {
                type: 'button',
                text: 'Earlier',
                'data-act': 'earlier',
                disabled: reviewIndex === 0 ? 'disabled' : null,
                onclick: function () { movePage(reviewIndex, reviewIndex - 1); }
            }),
            el('button', {
                type: 'button',
                text: 'Later',
                'data-act': 'later',
                disabled: reviewIndex >= pages.length - 1 ? 'disabled' : null,
                onclick: function () { movePage(reviewIndex, reviewIndex + 1); }
            }),
            el('button', {
                type: 'button',
                text: 'Add page',
                'data-act': 'add-page',
                disabled: pages.length >= MAX_PAGES ? 'disabled' : null,
                onclick: function () { onAddPage(); }
            })
        ]));

        prepareReviewCorners(page).then(function () {
            if (token !== viewToken || mode !== 'review') return null;
            return ensureScanic();
        }).then(function (scanic) {
            if (!scanic || token !== viewToken || mode !== 'review') return;
            mountEditor(scanic, page);
            scheduleRender();
            setStatus('Page ' + (reviewIndex + 1) + ' of ' + pages.length + '. Drag the corners. Rotate and choose a filter.');
        }).catch(function (err) {
            if (token !== viewToken || mode !== 'review') return;
            if (!page.corners) page.corners = fullImageCorners(page.sourceCanvas.width, page.sourceCanvas.height);
            scheduleRender();
            setStatus((err && err.message) || 'Page outline is unavailable. You can still rotate, filter, and save.');
        });
    }

    function mountEditor(scanic, page) {
        var host = bodyEl && bodyEl.querySelector('[data-act="editor"]');
        if (!host || !page || !page.sourceCanvas) return;
        destroyEditor();
        var display = applyFilter(page.sourceCanvas, page.filter);
        editor = scanic.createCornerEditor({
            container: host,
            image: display,
            corners: page.corners || undefined,
            toolbar: { enabled: false },
            magnifier: { zoom: 2, size: 110 },
            nudges: { enabled: true, steps: [1, 10] },
            onChange: function (corners) {
                var current = currentPage();
                if (!current || current !== page) return;
                current.corners = corners;
                scheduleRender();
            }
        });
        if (editor && typeof editor.getCorners === 'function') {
            page.corners = editor.getCorners() || page.corners;
        }
    }

    function setFilter(filterName) {
        var page = currentPage();
        if (!page || busy || page.filter === filterName) return;
        page.filter = filterName;
        var chips = bodyEl ? bodyEl.querySelectorAll('[data-act="filter"]') : [];
        Array.prototype.forEach.call(chips, function (chip) {
            var on = chip.getAttribute('data-filter') === filterName;
            chip.className = on ? 'is-on' : '';
            chip.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
        ensureScanic().then(function (scanic) {
            if (mode !== 'review' || currentPage() !== page) return;
            mountEditor(scanic, page);
            scheduleRender();
        }).catch(function () {
            scheduleRender();
        });
    }

    function scheduleRender() {
        if (renderTimer) clearTimeout(renderTimer);
        renderTimer = setTimeout(function () {
            renderTimer = 0;
            var page = currentPage();
            var token = viewToken;
            if (!page || mode !== 'review') return;
            renderPage(page).then(function () {
                if (token !== viewToken || mode !== 'review') return;
                updateResult(page);
                updateThumbImages();
            }).catch(function (err) {
                if (token !== viewToken) return;
                setStatus((err && err.message) || 'Could not update that page.');
            });
        }, 180);
    }

    function renderPage(page) {
        if (!page || !page.sourceCanvas) return Promise.reject(new Error('Retake the photo.'));
        var corners = page.corners || fullImageCorners(page.sourceCanvas.width, page.sourceCanvas.height);
        return ensureScanic().then(function (scanic) {
            return scanic.extractDocument(page.sourceCanvas, corners, { output: 'canvas' });
        }).then(function (result) {
            var output = result && result.output;
            if (!output || !output.width) throw new Error('crop');
            return output;
        }).catch(function () {
            return page.sourceCanvas;
        }).then(function (output) {
            var canvas = scaleCanvas(output);
            canvas = rotateCanvas(canvas, page.rotateDeg);
            canvas = scaleCanvas(canvas);
            canvas = applyFilter(canvas, page.filter);
            return canvasToJpeg(canvas).then(function (jpeg) {
                storeOutput(page, canvas, jpeg);
                return page;
            });
        });
    }

    function storeOutput(page, canvas, jpeg) {
        releaseUrl(page.fullUrl);
        page.jpeg = jpeg;
        page.width = canvas.width;
        page.height = canvas.height;
        try {
            var thumb = document.createElement('canvas');
            var tw = 160;
            var th = Math.max(1, Math.round(canvas.height * (tw / canvas.width)));
            thumb.width = tw;
            thumb.height = th;
            var tctx = thumb.getContext('2d');
            if (tctx) tctx.drawImage(canvas, 0, 0, tw, th);
            page.thumbUrl = thumb.toDataURL('image/jpeg', 0.7);
        } catch (e) {
            page.thumbUrl = page.thumbUrl || '';
        }
        try {
            page.fullUrl = URL.createObjectURL(new Blob([jpeg], { type: 'image/jpeg' }));
        } catch (e2) {
            page.fullUrl = page.thumbUrl;
        }
    }

    function updateResult(page) {
        var img = document.getElementById('temperDocScanResult');
        if (!img || !page) return;
        if (page.fullUrl) img.src = page.fullUrl;
    }

    function updateThumbImages() {
        if (!bodyEl) return;
        var buttons = bodyEl.querySelectorAll('[data-act="thumb"]');
        Array.prototype.forEach.call(buttons, function (btn) {
            var index = Number(btn.getAttribute('data-page-index'));
            var page = pages[index];
            var img = btn.querySelector('img');
            if (page && img && page.thumbUrl) img.src = page.thumbUrl;
        });
    }

    function refreshThumbStrip() {
        if (!bodyEl) return;
        var strip = bodyEl.querySelector('[data-act="thumbs"]');
        if (!strip) return;
        strip.textContent = '';
        pages.forEach(function (page, index) {
            strip.appendChild(buildThumb(page, index));
        });
    }

    function buildThumb(page, index) {
        var img = el('img', { alt: 'Page ' + (index + 1) });
        if (page.thumbUrl) img.src = page.thumbUrl;
        var btn = el('button', {
            type: 'button',
            class: index === reviewIndex ? 'is-on' : '',
            'data-act': 'thumb',
            'data-page-index': String(index),
            draggable: 'true',
            'aria-label': 'Review page ' + (index + 1),
            'aria-current': index === reviewIndex ? 'true' : 'false'
        }, [img]);
        btn.addEventListener('click', function () { selectPage(index); });
        btn.addEventListener('dragstart', function (event) {
            if (!event.dataTransfer) return;
            event.dataTransfer.setData('text/plain', String(index));
            event.dataTransfer.effectAllowed = 'move';
        });
        btn.addEventListener('dragover', function (event) {
            event.preventDefault();
            if (event.dataTransfer) event.dataTransfer.dropEffect = 'move';
        });
        btn.addEventListener('drop', function (event) {
            event.preventDefault();
            var from = Number(event.dataTransfer ? event.dataTransfer.getData('text/plain') : NaN);
            if (isNaN(from) || from === index) return;
            movePage(from, index);
        });
        return btn;
    }

    function selectPage(index) {
        if (busy || index === reviewIndex || !pages[index]) return;
        var leaving = currentPage();
        busy = true;
        renderPage(leaving).then(function () {
            busy = false;
            reviewIndex = index;
            showReview();
        }).catch(function () {
            busy = false;
            reviewIndex = index;
            showReview();
        });
    }

    function movePage(from, to) {
        if (to < 0 || to >= pages.length || from === to || !pages[from]) return;
        var current = currentPage();
        var item = pages.splice(from, 1)[0];
        pages.splice(to, 0, item);
        reviewIndex = Math.max(0, pages.indexOf(current));
        refreshThumbStrip();
        syncOrderButtons();
        setStatus('Page ' + (reviewIndex + 1) + ' of ' + pages.length + '. Drag the corners. Rotate and choose a filter.');
    }

    function syncOrderButtons() {
        if (!bodyEl) return;
        var earlier = bodyEl.querySelector('[data-act="earlier"]');
        var later = bodyEl.querySelector('[data-act="later"]');
        if (earlier) earlier.disabled = reviewIndex === 0;
        if (later) later.disabled = reviewIndex >= pages.length - 1;
    }

    function deletePage(index) {
        var page = pages[index];
        if (!page || busy) return;
        releasePage(page);
        pages.splice(index, 1);
        if (!pages.length) {
            reviewIndex = 0;
            showCamera();
            return;
        }
        if (reviewIndex > index) reviewIndex -= 1;
        if (reviewIndex >= pages.length) reviewIndex = pages.length - 1;
        showReview();
    }

    function onRetake() {
        if (busy || mode !== 'review' || !currentPage()) return;
        retakeIndex = reviewIndex;
        showCamera();
    }

    function onAddPage() {
        if (busy || pages.length >= MAX_PAGES) {
            if (pages.length >= MAX_PAGES) setStatus('This scan already has ' + MAX_PAGES + ' pages.');
            return;
        }
        var leaving = currentPage();
        retakeIndex = null;
        busy = true;
        renderPage(leaving).then(function () {
            busy = false;
            showCamera();
        }).catch(function () {
            busy = false;
            showCamera();
        });
    }

    function onSave() {
        if (busy || !pages.length) return;
        var page = currentPage();
        busy = true;
        setStatus('Preparing pages…');
        renderPage(page).then(function () {
            busy = false;
            showName();
        }).catch(function (err) {
            busy = false;
            setStatus((err && err.message) || 'Could not prepare that page.');
        });
    }

    function showName() {
        if (!pages.length) return;
        viewToken += 1;
        cameraToken += 1;
        destroyEditor();
        mode = 'name';
        clearBody();
        bodyEl.setAttribute('data-screen', 'name');
        setTitle('Save');
        setBar([cancelButton()]);
        setStatus('Name this document. Save builds one PDF.');
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
            el('button', { type: 'button', text: 'Back', 'data-act': 'name-back', onclick: function () { if (!busy) showReview(); } }),
            el('button', {
                type: 'button',
                class: 'primary',
                text: 'Save',
                'data-act': 'save-build',
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
        Promise.all(pages.map(function (page) {
            if (page.jpeg && page.width && page.height) return Promise.resolve(page);
            return renderPage(page);
        })).then(function () {
            var bytes = buildJpegPdf(pages.map(function (page) {
                return { jpeg: page.jpeg, width: page.width, height: page.height };
            }));
            var file = new File([bytes], name, { type: 'application/pdf', lastModified: Date.now() });
            var done = options.onComplete(file);
            close(true);
            if (done && typeof done.then === 'function') {
                done.catch(function () { /* caller reports upload errors */ });
            }
        }).catch(function (err) {
            busy = false;
            setStatus((err && err.message) || 'Could not build the PDF.');
        });
    }

    function open(nextOptions) {
        if (overlay) return;
        options = nextOptions || {};
        pages = [];
        reviewIndex = 0;
        retakeIndex = null;
        busy = false;
        autoArmed = true;
        stableCount = 0;
        injectStyles();
        overlay = el('div', { class: 'temper-doc-scan', role: 'dialog', 'aria-modal': 'true', 'aria-label': 'Scan document' });
        titleEl = el('strong', { text: 'Camera' });
        toolsEl = el('div', { class: 'temper-doc-scan-tools' });
        statusEl = el('div', { class: 'temper-doc-scan-status' });
        bodyEl = el('div', { class: 'temper-doc-scan-body' });
        overlay.appendChild(el('div', { class: 'temper-doc-scan-bar' }, [titleEl, toolsEl]));
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
        showCamera();
    }

    window.TemperDocScan = {
        open: open,
        close: function () { close(true); },
        buildJpegPdf: buildJpegPdf
    };
})();
