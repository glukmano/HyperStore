# AI Subsystem & Model Context Protocol (MCP) Documentation

This directory contains specifications for the internal AI Multi-Agent platform, Laravel AI integrations, MCP tools, Autonomy governance, and safety controls.

## AI Architecture Principles

1. **Platform Subsystem**: AI is deeply integrated across commerce domains (Orchestrator, Dev, Security, Support, Marketing, Fraud, SEO).
2. **Laravel AI SDK & Laravel MCP**: First-party frameworks serve as the native integration and server layer.
3. **Autonomy Governance**:
   - `Disabled`
   - `Read Only`
   - `Diagnose`
   - `Execute With Approval`
   - `Full Autonomous Control` (Default: **OFF**)
4. **Hard External Kill Switch**: Mandatory CLI / environment override (`AI_AUTONOMY_ENABLED=false` / `php artisan ai:kill`) to halt autonomous actions immediately.
5. **Comprehensive Audit Log**: Every privileged AI decision, tool call, and state alteration is recorded in an immutable audit ledger with correlation IDs.
