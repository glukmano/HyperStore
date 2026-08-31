---
name: ai-agents-mcp
description: Enforces Laravel AI/MCP integration, multi-agent platform architecture, Full Control governance, audit logging, and external Kill Switch. Use when developing AI agents, MCP tools, or autonomy controls.
---

# AI Agents Subsystem & Model Context Protocol (MCP)

## Master Authority Reference
- **Document**: [PROJECT_MASTER_PLAN.md](file:///Volumes/Lukman/dev/Projects/HyperStore/PROJECT_MASTER_PLAN.md) (Sections 23, 24, 26)

## Core Rules & Mandates

1. **Framework Integration**:
   - Built with **Laravel AI SDK** and **Laravel MCP**.
   - Pluggable AI provider configuration (OpenAI, Anthropic, Gemini, local models).
   - MCP tools expose authorized domain services and MUST NEVER bypass application domain rules or permissions.
2. **Autonomy Modes & Defaults**:
   - `Disabled`
   - `Read Only`
   - `Diagnose`
   - `Execute With Approval`
   - `Full Autonomous Control`
   - **Full Autonomous Control is OFF by default.**
3. **Mandatory External Kill Switch**:
   - Provide non-bypassable CLI and environment overrides to immediately disable autonomous execution:
     ```bash
     AI_AUTONOMY_ENABLED=false
     php artisan ai:disable
     php artisan ai:kill
     ```
4. **Immutable Audit Trail**:
   - Every privileged AI action, tool invocation, and state mutation must be recorded in an auditable log with:
     `Agent ID`, `Authority Level`, `Tool Invoked`, `Payload`, `Affected Resources`, `Result`, `Timestamp`, `Correlation ID`.
5. **Master Plan Inviolability**:
   - No autonomous AI agent may rewrite or weaken `PROJECT_MASTER_PLAN.md`.

## Pre-Execution Checklist
- [ ] Are MCP tools invoking domain actions with proper authorization checks?
- [ ] Is the external kill switch functional and tested?
- [ ] Are all autonomous AI actions piped to the immutable audit log?

## Forbidden Shortcuts
- ❌ Granting AI tools direct raw database write access without domain service validation.
- ❌ Enabling full autonomous control by default.
- ❌ Omitting correlation IDs and audit records for agent actions.

## Validation Steps
1. Test external kill switch CLI command (`php artisan ai:kill`) to ensure immediate agent execution termination.
2. Verify MCP tool authorization gating against unauthorized roles.
3. Validate audit log completeness for simulated agent operations.
