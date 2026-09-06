<?php

declare(strict_types=1);

namespace Modules\Ledger\Enums;

enum SystemAccountRole: string
{
    case PAYMENT_CLEARING = 'payment_clearing';
    case CUSTOMER_FUNDS_LIABILITY = 'customer_funds_liability';

    /**
     * Phase-21 / ADR-0150: three distinct Store Value liability roles
     * (never one generic role) because Wallet, Store Credit, and Gift Card
     * balances carry different regulatory/accounting treatment even though
     * they share one domain engine (modules/Wallet).
     */
    case WALLET_LIABILITY = 'wallet_liability';
    case STORE_CREDIT_LIABILITY = 'store_credit_liability';
    case GIFT_CARD_LIABILITY = 'gift_card_liability';

    /**
     * Phase-21 / ADR-0150: the counterpart for a Store Value entry that has
     * no cash movement of its own (manual issuance/clawback, expiration
     * breakage) — introduced because the pre-existing Ledger model has no
     * expense/revenue account at all (source-audited: only PAYMENT_CLEARING
     * and CUSTOMER_FUNDS_LIABILITY existed prior to this phase).
     */
    case STORE_VALUE_NON_CASH_ADJUSTMENT = 'store_value_non_cash_adjustment';
}
