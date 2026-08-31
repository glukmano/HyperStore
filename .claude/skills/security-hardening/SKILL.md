---
name: security-hardening
description: Enforces defense-in-depth security, RBAC/policies, CSRF, input validation, output sanitization, SSRF protection, secure uploads, tenant boundaries, and audit trails. Use when reviewing security, touching auth, or handling untrusted input.
---

# Security Hardening & Threat Mitigation

## Master Authority Reference
- **Document**: [PROJECT_MASTER_PLAN.md](file:///Volumes/Lukman/dev/Projects/HyperStore/PROJECT_MASTER_PLAN.md) (Sections 24, 26, 27)

## Core Rules & Mandates

1. **Security is Definition of Done**:
   - A feature cannot be considered complete without strict authorization, input validation, output escaping, and regression security checks.
2. **Access Control & Multi-Tenancy Defense**:
   - Granular RBAC via `spatie/laravel-permission` and Laravel Policies.
   - Enforce 2FA capability for privileged administrative and vendor accounts.
   - Scrict tenant and store isolation gates on every database read/write.
3. **SSRF & Outbound Network Safety**:
   - Outgoing HTTP calls (webhooks, supplier sync, API connectors) must use an SSRF-safe HTTP client wrapper.
   - Disallow private IP ranges (RFC 1918, RFC 3927, loopback addresses).
4. **File Upload Security**:
   - Validate file contents via MIME inspection and virus/malware scanners where required.
   - Store uploads with randomized hashes on private S3/object storage buckets; serve via signed URLs when access is restricted.
5. **Dependency & Secret Hygiene**:
   - Run `composer audit` and `npm audit` continuously.
   - Never commit secrets, API keys, or private tokens to Git; mask sensitive fields in logs and error traces.

## Pre-Execution Checklist
- [ ] Are Laravel Policies attached to all controller and Livewire actions?
- [ ] Are outgoing network requests screened against SSRF vulnerabilities?
- [ ] Are uploaded files validated for strict MIME types and file extension spoofing?

## Forbidden Shortcuts
- ❌ Disabling CSRF protection on forms or webhooks.
- ❌ Bypassing tenant authorization policies.
- ❌ Trusting client-supplied price or permission values.
- ❌ Making unvalidated outbound HTTP calls to user-supplied URLs.

## Validation Steps
1. Run automated security test suites (CSRF, XSS, SSRF, IDOR, SQL injection).
2. Execute `composer audit` and `npm audit`.
3. Test cross-tenant data access attempts to assert 403/404 responses.
