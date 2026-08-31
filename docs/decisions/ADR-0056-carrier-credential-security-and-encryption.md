# ADR-0056: Carrier Provider Credential Security and Encryption Architecture

## Status
Accepted

## Context
Carrier API keys, client secrets, and account tokens must be securely stored and protected from unauthorized access or exposure.

## Decision
1. `CarrierCredential` entity stores credentials encrypted at rest using Laravel `Crypt` (`encrypted` Eloquent cast).
2. Credential fields are hidden (`$hidden = ['credentials', 'api_secret']`) and write-only through API/UI.
3. GET endpoints, Livewire component state, logs, and audit trails strictly redact secret values (e.g. `***MASKED***`).
4. Updates require dedicated permission (`shipping.credentials.manage`). Cross-tenant access returns 404.

## Consequences
- Complete zero-leakage security for carrier credentials.
