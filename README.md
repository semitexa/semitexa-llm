# Semitexa LLM

LLM assistant for Semitexa with console-based skill discovery and execution. Talks to a local Ollama, a remote Ollama, or Google Gemini.

## Install

Included in every project created by the installer (https://semitexa.com/install.sh).

## Purpose

Integrates LLM capabilities into the Semitexa CLI. Console commands marked with `#[AsAiSkill]` are discoverable by AI agents with structured metadata including risk assessment and execution policies.

## Role in Semitexa

Depends on `semitexa/core` for attribute discovery and the console command system. Skills are registered via `ClassDiscovery` and exposed to LLM agents as a structured manifest with execution constraints.

## Key Features

- `#[AsAiSkill]` attribute for skill discovery, `#[AsAiPersona]` for personas
- `SkillRegistry` builds the manifest from ClassDiscovery
- Execution policies: `AiExecutionKind`, `AiRiskLevel`, `AiConfirmationMode`
- Providers: `LocalOllamaProvider`, `RemoteOllamaProvider`, `GeminiProvider`, selected by `LlmProviderResolver`
- Console: `ai` (interactive assistant), `ai:skills` (list skills), `prompt:eval` (send a prompt to the configured model)

## Configuration

`LLM_BACKEND` selects the provider: `local` (default), `remote_ollama` or `gemini`. An unset or unknown value falls back to `local`.

| Backend | Keys (defaults) |
|---|---|
| `local` | `LLM_BASE_URL` (`http://127.0.0.1:11434`), `LLM_MODEL` (`gemma3:4b`), `LLM_TIMEOUT` (60), `LLM_RETRIES` (1), `LLM_CONNECT_TIMEOUT` (5) |
| `remote_ollama` | `LLM_REMOTE_OLLAMA_URL` (required), `LLM_REMOTE_OLLAMA_MODEL` (`gemma4:e2b`), `LLM_REMOTE_OLLAMA_TIMEOUT` (120), `LLM_REMOTE_OLLAMA_RETRIES` (2), `LLM_REMOTE_OLLAMA_CONNECT_TIMEOUT` (5) |
| `gemini` | `GEMINI_API_KEY` (a Gemini API key from Google AI Studio), `GEMINI_MODEL` (`gemini-2.5-flash`), `GEMINI_BASE_URL`, `GEMINI_TIMEOUT` (120), `GEMINI_RETRIES` (2), `GEMINI_CONNECT_TIMEOUT` (5), `GEMINI_CONTEXT_CACHE` (off) |

`LLM_DECIDER_MODEL` (optional) routes silent classification and tool-picking to a cheaper model on the same provider. Only providers that can switch model honour it (Gemini today); on the Ollama backends it is ignored.

`LLM_PROVIDER=ollama` is not read by this package: it tells `bin/semitexa server:start` to add the `docker-compose.ollama.yml` overlay, which runs an Ollama container in the project stack and points `LLM_BASE_URL` at it (`http://ollama:11434`).

Docs: https://semitexa.com/docs/llm/providers

## Notes

Skills are only useful once a provider is reachable. Execution policies ensure destructive operations require explicit confirmation.
