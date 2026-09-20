<?php
declare(strict_types=1);

// Copied into a disposable fixture root. No HTTP transport or credentials exist here.
namespace WeeklyCliFixture {
    final class State
    {
        public static function read(): array
        {
            return json_decode(file_get_contents(__DIR__ . '/broker.json'), true, 512, JSON_THROW_ON_ERROR);
        }

        public static function write(array $state): void
        {
            file_put_contents(__DIR__ . '/broker.json', json_encode($state, JSON_THROW_ON_ERROR), LOCK_EX);
        }

        public static function record(array $event): void
        {
            $state = self::read();
            $state['trace'][] = $event + ['at' => $state['now']];
            self::write($state);
        }

        public static function open(array $order): bool
        {
            return !in_array($order['status'], ['filled', 'canceled', 'expired', 'rejected'], true);
        }
    }

    final class FrozenDateTimeImmutable extends \DateTimeImmutable
    {
        public function __construct(string $datetime = 'now', ?\DateTimeZone $timezone = null)
        {
            parent::__construct($datetime === 'now' ? State::read()['now'] : $datetime, $timezone);
        }
    }

    function gmdate(string $format, ?int $timestamp = null): string
    {
        return \gmdate($format, $timestamp ?? (new \DateTimeImmutable(State::read()['now']))->getTimestamp());
    }
}

namespace FulltimeTrading\Data {
    final class HttpClient
    {
        public function __call(string $name, array $arguments): never
        {
            throw new \RuntimeException('Fixture forbids HTTP: ' . $name);
        }
    }
}

namespace FulltimeTrading\Trading {
    use WeeklyCliFixture\State;

    final class AlpacaPaperClient
    {
        public function __construct($http, string $url)
        {
            if ($url !== 'https://paper-api.alpaca.markets/v2') {
                throw new \RuntimeException('Fixture rejects nonpaper host');
            }
        }

        public function account(): array
        {
            $cash = State::read()['capital'];
            foreach (State::read()['orders'] as $order) {
                $cash -= ($order['side'] === 'buy' ? 1 : -1) * (float) $order['filled_qty'] * (float) $order['filled_avg_price'];
            }
            $value = array_sum(array_column($this->positions(), 'market_value'));
            return ['id' => 'weekly-cli-fixture', 'multiplier' => '2', 'shorting_enabled' => true, 'status' => 'ACTIVE',
                'trading_blocked' => false, 'account_blocked' => false, 'cash' => (string) $cash,
                'equity' => (string) ($cash + $value), 'buying_power' => (string) (2 * ($cash + $value) - $value)];
        }

        public function clock(): array { return State::read()['clock']; }
        public function calendar($start, $end): array { return State::read()['calendar']; }

        public function positions(): array
        {
            $qty = 0;
            foreach (State::read()['orders'] as $order) {
                $qty += ($order['side'] === 'buy' ? 1 : -1) * (int) $order['filled_qty'];
            }
            return $qty === 0 ? [] : [['symbol' => 'MSFT', 'qty' => (string) $qty, 'side' => 'long',
                'avg_entry_price' => '492', 'market_value' => (string) ($qty * 492), 'current_price' => '492']];
        }

        public function openOrders(): array { return array_values(array_filter(State::read()['orders'], State::open(...))); }
        public function asset($symbol): array { return ['symbol' => $symbol, 'status' => 'active', 'tradable' => true]; }

        public function orderByClientOrderId(string $id): ?array
        {
            State::record(['kind' => 'lookup', 'client_order_id' => $id]);
            return State::read()['orders'][$id] ?? null;
        }

        public function submitOrder(array $body): array
        {
            $state = State::read(); $id = $body['client_order_id'];
            if (isset($state['orders'][$id])) { throw new \RuntimeException('Duplicate fixture POST'); }
            foreach ($this->openOrders() as $order) {
                if ($order['symbol'] === $body['symbol'] && $order['side'] !== $body['side']) {
                    throw new \RuntimeException('Fixture rejects opposing open simple orders');
                }
            }
            if ($body['side'] === 'buy' && ($body['time_in_force'] !== 'opg'
                || (new \DateTimeImmutable($state['now']))->format('H:i:s') >= '09:27:00')) {
                throw new \RuntimeException('Fixture rejects late or non-OPG BUY');
            }
            $order = $body + ['id' => 'fixture-' . count($state['orders']), 'status' => 'new',
                'filled_qty' => '0', 'filled_avg_price' => null];
            $state['orders'][$id] = $order;
            $state['trace'][] = ['kind' => 'submit', 'body' => $body, 'at' => $state['now']];
            State::write($state);
            return $order;
        }

        public function cancelOrder(string $id): array
        {
            $state = State::read(); $found = false;
            foreach ($state['orders'] as &$order) {
                if ($order['id'] !== $id) { continue; }
                $found = true;
                if (State::open($order)) { $order['status'] = 'canceled'; }
            }
            unset($order);
            if (!$found) { throw new \RuntimeException('Unknown fixture cancellation'); }
            $state['trace'][] = ['kind' => 'cancel', 'order_id' => $id, 'at' => $state['now']];
            State::write($state);
            return [];
        }
    }
}

namespace FulltimeTrading\Notifications {
    use WeeklyCliFixture\State;

    final class TelegramNotifier
    {
        public static function fromEnv($http): self { return new self(); }
        public function sendMessage(string $text, bool $disableNotification = false): array
        {
            State::record(['kind' => 'telegram', 'text' => $text]);
            return ['message_id' => count(State::read()['trace'])];
        }
    }
}
