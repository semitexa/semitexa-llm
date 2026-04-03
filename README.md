# Semitexa LLM

Self-hosted LLM assistant with console-based skill discovery and execution.

## Purpose

Integrates LLM capabilities into the Semitexa CLI. Console commands marked with `#[AsAiSkill]` are discoverable by AI agents with structured metadata including risk assessment and execution policies.

## Role in Semitexa

Depends on `semitexa/core` for attribute discovery and the console command system. Skills are registered via `ClassDiscovery` and exposed to LLM agents as a structured manifest with execution constraints.

## Key Features

- `#[AsAiSkill]` attribute for skill discovery
- `SkillRegistry` builds manifest from ClassDiscovery
- Execution policies: `AiExecutionKind`, `AiRiskLevel`, `AiConfirmationMode`
- Structured skill metadata for LLM agents
- Ollama integration for local inference

## Notes

Optional package. Skills are only exposed when an LLM provider is configured. Execution policies ensure destructive operations require explicit confirmation.
