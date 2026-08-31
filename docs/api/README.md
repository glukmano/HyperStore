# REST API & Webhooks Documentation

This directory contains API specifications, OpenAPI/Swagger schemas, webhook documentation, and integration standards for the **Hyper Commerce Platform**.

## API Architecture Principles

1. **API-Ready from Day One**: Business domain actions are decoupled from Blade/Livewire and accessible via REST API.
2. **Authentication**: Token-based authentication using **Laravel Sanctum**.
3. **Consistent JSON Envelopes**: Uniform response formatting for data, pagination, and errors.
4. **Idempotency**: Critical state-mutating endpoints (e.g., checkout, payments, refunds, payouts) require `Idempotency-Key` headers.
5. **Signed Webhooks**: Outgoing and incoming webhooks are cryptographically signed (HMAC SHA-256) with replay protection, retry backoffs, and secret rotation capabilities.

## Documentation Index

- `spec.yaml` / `openapi.json`: Formal API contract specification (to be generated per phase).
- `webhooks.md`: Webhook events catalog and verification guides.
- `error_codes.md`: Standard error taxonomy and HTTP status code mappings.
