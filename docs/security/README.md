# Security & Compliance Documentation

This directory contains security policies, threat modeling, tenant isolation guarantees, SSRF protections, cryptography standards, and penetration testing guidelines.

## Security Non-Negotiables

1. **Security in Definition of Done**: Features are incomplete without security review, policy gates, and regression tests.
2. **Tenant Isolation**: Mandatory automated tests verifying zero data leakage across tenants and stores.
3. **Privileged Access**: 2FA capability, RBAC permission verification, and audit logging for all administrative operations.
4. **Integration Safety**: SSRF-safe HTTP client wrapper for outgoing webhook calls and supplier connector integrations.
5. **Data Protection**: Strict CSRF tokens, output escaping, parameterized queries, and financial idempotency.
