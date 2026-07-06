# AI-Assisted Development Setup Reference – Hope Baptist Church Treasurer Workspace

**Purpose:**  
This document records the agreed-upon AI development configuration for coding and Treasurer Assistant tasks in VSCodium on Kubuntu Linux. It is maintained separately from the Treasurer’s Guide and Temper Prompt.

**Last Updated:** June 30, 2026  
**Thread:** Development / Architecture (exclusive)

## System Specifications
- **Operating System:** Kubuntu Linux
- **Editor:** VSCodium (open-source VS Code fork)
- **Hardware:**
  - **CPU:** AMD Ryzen 9 5900XT (16 cores / 32 threads, Zen 3 architecture, up to 4.8 GHz boost, AM4 platform with DDR4 RAM)
  - **GPU (Primary):** NVIDIA RTX 5060 Ti 16 GB VRAM (Blackwell architecture)
  - **Secondary GPU Available:** NVIDIA RTX A4000 16 GB GDDR6 ECC (Ampere architecture)
  - **Key Notes:** Strong local inference capability when needed.

## Core Stack (Updated June 30, 2026)
- **Primary AI Integration:** **Grok Build** (agentic workflows with this Grok instance) — preferred for structured, multi-step development tasks, code generation, iterative improvements, and complex refactoring.
- **Secondary Local Interface:** OpenCode (local LLM interface for autocomplete, edits, and fallback tasks).
- **Local Inference Engine:** Ollama (GPU offloading enabled via CUDA).
- **Optional Companion:** Aider (terminal-based for larger refactors).

## Primary Workflow Recommendation
Use **Grok Build** as the main driver for Temper development (roadmap tasks, feature implementation, debugging).  
Use **OpenCode** + local Ollama models for quick suggestions, offline work, or when Grok Build is not active.

## Installed Local Models (Secondary/Fallback via OpenCode)
**Default / Best Working Model:** `qwen3-coder-opt-16k:latest` (12 GB)

Full list of installed models (as of June 30, 2026):
- `qwen3-coder-opt-16k:latest` (default, recommended)
- `danielsheep/Qwen3-Coder-30B-A3B-Instruct-1M-Unsloth:UD-IQ3_XXS`
- Various `qwen3-coder-next` variants (16k/24k, different quantizations)
- `qwen3.6:35b-a3b` series (16k/24k/32k)
- `qwen3-coder:30b` series
- Smaller models: `qwen2.5-coder:14b`, `llama3.1:8b`, etc.

**Recommended Local Settings (in OpenCode):**
- Default model: `qwen3-coder-opt-16k:latest`
- Temperature: 0.5 (for deterministic financial/logic code)
- Context length: 16384 (or maximum supported by the model)

## Hardware Notes
- RTX 5060 Ti preferred for local Ollama inference through OpenCode.
- Grok Build workflows do not consume local GPU resources.