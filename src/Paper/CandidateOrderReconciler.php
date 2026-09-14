<?php

declare(strict_types=1);

namespace FulltimeTrading\Paper;

/** Transport only; the caller holds the account lease and validates release/account/risk gates. */
final readonly class CandidateOrderReconciler
{
    public function __construct(private CandidateLedger $ledger, private CandidateBrokerGateway $broker) { }

    public function refresh(string $decision): array
    {
        $i = $this->ledger->intent($decision) ?? throw new \RuntimeException('Unknown candidate decision.');
        CandidateOrder::assert($i);
        if ((int) $i['attempt_count'] === 0) { return ['status' => 'not_submitted', 'intent' => $i]; }
        $order = $this->broker->orderByClientOrderId($i['client_order_id']);
        if ($order === null) {
            // A 404 does not prove that a timed-out POST was rejected.
            return ['status' => 'unresolved_lookup', 'intent' => $i];
        }
        return ['status' => 'observed', 'intent' => $this->ledger->observe($decision, $order)];
    }

    public function submit(string $decision): array
    {
        $i = $this->ledger->intent($decision) ?? throw new \RuntimeException('Unknown candidate decision.');
        CandidateOrder::assert($i);
        if ((int) $i['attempt_count'] === 0) {
            CandidateBrokerOrderPolicy::assertCompatible($i, $this->ledger->active($i['run_id']));
        }
        if (!$this->ledger->claim($decision)) { return $this->refresh($decision); }
        try {
            $order = $this->broker->submitOrder($i['payload']['body']);
        } catch (\Throwable $e) {
            $this->ledger->ambiguous($decision);
            return ['status' => 'ambiguous_submission', 'intent' => $this->ledger->intent($decision)];
        }
        // A malformed broker response is not silently downgraded to a transport timeout.
        return ['status' => 'observed', 'intent' => $this->ledger->observe($decision, $order)];
    }

    public function cancel(string $decision, string $reason): array
    {
        $refreshed = $this->refresh($decision);
        if ($refreshed['status'] === 'unresolved_lookup') { return $refreshed; }
        if ($this->ledger->requestCancel($decision, $reason, $refreshed['status'] === 'observed')) {
            $i = $this->ledger->intent($decision);
            try { $this->broker->cancelOrder($i['order_id']); }
            catch (\Throwable $e) { return ['status' => 'cancel_unconfirmed', 'intent' => $i]; }
        }
        // DELETE success never releases a share reservation. Only GET terminal state can.
        $result = $this->refresh($decision); $i = $result['intent'];
        if (!in_array($i['status'], CandidateOrder::TERMINAL, true) && isset($i['payload']['cancel_request']['at'])
            && time() - strtotime($i['payload']['cancel_request']['at']) > 180) { $result['status'] = 'cancel_stuck'; }
        return $result;
    }
}
