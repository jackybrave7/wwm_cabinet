<?php
declare(strict_types=1);

namespace Wwm\Services;

/**
 * Tilda form POST (Site Settings → Forms → Webhook) → cabinet payment payload.
 * Tilda can call the cabinet directly; no bl-school hop.
 */
final class TildaPaymentPayload
{
    /** @var array<string, int> */
    private const PRODUCT_MAP = [
        'Video course The Soul of Place: Interiors and Exteriors in Watercolor by Alvaro Castagnet' => 193,
        'Video course Watercolor textures by Angus McEwan' => 201,
        "Video course 'Watercolor roses' by La Fe" => 199,
        "Video course 'Watercolor worlds' by Alexander Votsmush" => 321,
        "Video course 'Glowing watercolors' by Nono Garcia" => 329,
        "Video course 'Watercolor expressionism' by Elke Memmler" => 188,
        'Videokurs Aquarell-Expressionismus von Elke Memmler' => 191,
        'Alvaro Castagnet' => 193,
        'Angus McEwan' => 201,
        'La Fe' => 199,
        'Alexander Votsmush' => 321,
        'Nono Garcia' => 329,
        'Watercolor expressionism' => 188,
        'Aquarell-Expressionismus' => 191,
    ];

    /**
     * @param array<string, mixed> $payload
     */
    public static function isPing(array $payload): bool
    {
        if ($payload === []) {
            return true;
        }
        if (strtolower(trim((string)($payload['source'] ?? ''))) === 'tilda'
            && (int)($payload['id_goods'] ?? 0) > 0) {
            return false;
        }
        if (self::emailOf($payload) !== '') {
            return false;
        }
        $payment = self::paymentOf($payload);
        if (is_array($payment) && $payment !== []) {
            return false;
        }
        if (self::productsOf($payload, $payment) !== []) {
            return false;
        }

        return true;
    }

    /**
     * Raw Tilda form, not the already-normalized JSON (source=tilda + id_goods).
     *
     * @param array<string, mixed> $payload
     */
    public static function isNative(array $payload): bool
    {
        if (strtolower(trim((string)($payload['source'] ?? ''))) === 'tilda'
            && (int)($payload['id_goods'] ?? 0) > 0) {
            return false;
        }

        return self::emailOf($payload) !== ''
            || self::productsOf($payload, self::paymentOf($payload)) !== [];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function normalize(array $payload): array
    {
        $payment = self::paymentOf($payload);
        $products = self::productsOf($payload, $payment);
        $email = self::emailOf($payload);
        $name = self::first([
            $payload['Name'] ?? null,
            $payload['name'] ?? null,
            is_array($payment) ? ($payment['delivery_fio'] ?? null) : null,
        ]);

        $amount = 0.0;
        $hasAmount = false;
        if (is_array($payment) && array_key_exists('amount', $payment)) {
            $hasAmount = true;
            $amount = self::toFloat($payment['amount']);
        } elseif (array_key_exists('amount', $payload) || array_key_exists('Amount', $payload)) {
            $hasAmount = true;
            $amount = self::toFloat($payload['amount'] ?? $payload['Amount'] ?? 0);
        }
        if (!$hasAmount) {
            foreach ($products as $product) {
                $amount += $product['price'] * $product['quantity'];
            }
        }

        $currency = strtoupper(self::first([
            is_array($payment) ? ($payment['currency'] ?? null) : null,
            $payload['currency'] ?? null,
            $payload['Currency'] ?? null,
            'USD',
        ]));

        $orderId = self::first([
            is_array($payment) ? ($payment['orderid'] ?? null) : null,
            is_array($payment) ? ($payment['order_id'] ?? null) : null,
            $payload['orderid'] ?? null,
            $payload['Orderid'] ?? null,
        ]);
        $paymentId = self::first([
            $payload['paymentid'] ?? null,
            $payload['Paymentid'] ?? null,
            is_array($payment) ? ($payment['paymentid'] ?? null) : null,
        ]);
        $tranId = self::first([
            $payload['tranid'] ?? null,
            $payload['Tranid'] ?? null,
            is_array($payment) ? ($payment['tranid'] ?? null) : null,
        ]);
        if (self::isGenericTran($tranId)) {
            $tranId = '';
        }
        if ($orderId === '') {
            $orderId = self::first([$paymentId, $tranId]);
        }

        if (count($products) > 1) {
            $products = [self::pickOneProduct($products, $amount, $amount <= 0)];
        }
        $product = $products[0] ?? ['name' => '', 'price' => 0.0, 'quantity' => 1.0, 'externalid' => ''];
        $idGoods = self::goodsId($product);

        $keys = [];
        $add = static function (string $prefix, string $value) use (&$keys): void {
            $value = trim($value);
            if ($value === '') {
                return;
            }
            $key = $prefix . $value;
            if (!in_array($key, $keys, true)) {
                $keys[] = $key;
            }
        };
        $add('tilda:order:', $orderId);
        $add('tilda:payment:', $paymentId);
        $add('tilda:tran:', $tranId);
        $bit = trim($product['externalid']) !== '' ? trim($product['externalid']) : trim($product['name']);
        $add('tilda:fp:', md5(strtolower($email) . '|' . $amount . '|' . $currency . '|' . $bit));

        $promocode = self::first([
            $payload['promocode'] ?? null,
            $payload['Promocode'] ?? null,
            $payload['promo'] ?? null,
            is_array($payment) ? ($payment['promocode'] ?? null) : null,
        ]);

        return [
            'source' => 'tilda',
            'email' => strtolower($email),
            'name' => $name,
            'id_goods' => $idGoods ?? 0,
            'amount_original' => $amount,
            'currency_original' => $currency,
            'idempotency_key' => $keys[0] ?? '',
            'external_keys' => $keys,
            'order_id' => $orderId,
            'payment_id' => $paymentId,
            'promocode' => $promocode,
            'is_free' => $amount <= 0,
            'id_account' => $keys[0] ?? '',
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function emailOf(array $payload): string
    {
        $payment = self::paymentOf($payload);

        return strtolower(self::first([
            $payload['Email'] ?? null,
            $payload['email'] ?? null,
            is_array($payment) ? ($payment['email'] ?? null) : null,
        ]));
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    private static function paymentOf(array $payload): ?array
    {
        $payment = $payload['payment'] ?? null;
        if (is_array($payment)) {
            return $payment;
        }
        if (!is_string($payment) || trim($payment) === '') {
            return null;
        }
        $decoded = json_decode($payment, true);
        if (!is_array($decoded)) {
            $decoded = json_decode(urldecode($payment), true);
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed>|null $payment
     * @return list<array{name: string, price: float, quantity: float, externalid: string}>
     */
    private static function productsOf(array $payload, ?array $payment): array
    {
        $raw = null;
        if (isset($payload['products']) && is_array($payload['products'])) {
            $raw = $payload['products'];
        } elseif (is_array($payment)) {
            foreach (['products', 'items', 'goods'] as $key) {
                if (isset($payment[$key]) && is_array($payment[$key])) {
                    $raw = $payment[$key];
                    break;
                }
            }
        }
        if ($raw === null) {
            return [];
        }

        $out = [];
        foreach ($raw as $item) {
            if (!is_array($item)) {
                continue;
            }
            $qty = self::toFloat($item['quantity'] ?? $item['qty'] ?? 1);
            if ($qty <= 0) {
                $qty = 1;
            }
            $out[] = [
                'name' => self::first([$item['name'] ?? null, $item['title'] ?? null, $item['product'] ?? null]),
                'price' => self::toFloat($item['price'] ?? $item['amount'] ?? 0),
                'quantity' => $qty,
                'externalid' => trim((string)($item['externalid'] ?? $item['external_id'] ?? '')),
            ];
        }

        return $out;
    }

    /**
     * @param list<array{name: string, price: float, quantity: float, externalid: string}> $products
     * @return array{name: string, price: float, quantity: float, externalid: string}
     */
    private static function pickOneProduct(array $products, float $amount, bool $isFree): array
    {
        if (!$isFree && $amount > 0) {
            $matches = [];
            foreach ($products as $product) {
                if (abs(($product['price'] * $product['quantity']) - $amount) < 0.02) {
                    $matches[] = $product;
                }
            }
            if (count($matches) === 1) {
                return $matches[0];
            }
        }

        return $products[count($products) - 1];
    }

    /**
     * @param array{name: string, externalid: string} $product
     */
    private static function goodsId(array $product): ?int
    {
        $name = trim($product['name']);
        $external = trim($product['externalid']);
        $haystack = self::norm($name);

        if (str_contains($haystack, 'aquarell-expressionismus')
            || (str_contains($haystack, 'videokurs') && str_contains($haystack, 'elke'))) {
            return 191;
        }
        if (str_contains($haystack, 'watercolor expressionism') && str_contains($haystack, 'elke')) {
            return 188;
        }

        $bestLen = 0;
        $bestId = null;
        foreach (self::PRODUCT_MAP as $label => $id) {
            $needle = self::norm($label);
            if ($needle === '' || $needle === 'elke memmler') {
                continue;
            }
            if ($haystack !== '' && str_contains($haystack, $needle) && strlen($needle) > $bestLen) {
                $bestLen = strlen($needle);
                $bestId = $id;
            }
            if ($external !== '' && self::norm($external) === $needle) {
                return $id;
            }
        }
        if ($bestId !== null) {
            return $bestId;
        }
        if ($external !== '' && ctype_digit($external)) {
            return (int)$external;
        }

        return null;
    }

    private static function isGenericTran(string $value): bool
    {
        $t = strtolower(trim($value));
        if ($t === '') {
            return true;
        }
        $generic = ['stripe', 'paypal', 'applepay', 'googlepay', 'card', 'free'];

        return in_array($t, $generic, true) || (strlen($t) < 12 && !ctype_digit($t));
    }

    private static function norm(string $value): string
    {
        $value = str_replace(['«', '»', '"', "'", '’'], '', $value);
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);

        return mb_strtolower($value);
    }

    /** @param list<mixed> $values */
    private static function first(array $values): string
    {
        foreach ($values as $value) {
            if (is_string($value) || is_int($value) || is_float($value)) {
                $text = trim((string)$value);
                if ($text !== '') {
                    return $text;
                }
            }
        }

        return '';
    }

    private static function toFloat(mixed $value): float
    {
        $raw = str_replace([' ', ','], ['', '.'], trim((string)$value));
        return $raw === '' ? 0.0 : (float)$raw;
    }
}
