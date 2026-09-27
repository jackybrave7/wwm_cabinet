<?php
declare(strict_types=1);

/**
 * Repair Tilda webhook payloads for 100% promos / gift coupons (paid total = 0).
 * Deploy to bl-school: public/api/lib/TildaOrderEnrich.php
 */
final class TildaOrderEnrich
{
    /**
     * @param array<string, mixed> $order  TildaPayload::parse() result (mutated copy returned)
     * @param array<string, mixed> $post   raw $_POST
     * @param array<string, mixed> $config tilda-avo.config.php
     * @return array<string, mixed>
     */
    public static function apply(array $order, array $post, array $config): array
    {
        $order = self::normalizePaidAmount($order, $post);

        if (!is_array($order['products'] ?? null)) {
            $order['products'] = [];
        }
        if ($order['products'] === []) {
            $fromPost = self::extractProductsFromPost($post);
            if ($fromPost !== []) {
                $order['products'] = $fromPost;
            }
        }

        $promo = self::promocodeFromPost($post, $order);
        if ($promo !== '') {
            $order['promocode'] = $promo;
        }

        $paid = (float)($order['amount'] ?? 0);
        $catalog = self::catalogSubtotalFromPost($post, $order);
        if ($paid <= 0 && ($catalog > 0 || $promo !== '' || self::isPaidStatusPost($post))) {
            $order['is_free'] = true;
        }

        if ($order['products'] === [] && (!empty($order['is_free']) || $paid <= 0.0)) {
            $synthetic = self::syntheticProductForFreeOrder($order, $post, $config);
            if ($synthetic !== null) {
                $order['products'] = [$synthetic];
            }
        }

        return $order;
    }

    /**
     * @param array<string, mixed> $order
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    private static function normalizePaidAmount(array $order, array $post): array
    {
        $paid = self::paidAmountFromPost($post);
        if ($paid !== null) {
            $order['amount'] = $paid;
        }

        return $order;
    }

    /**
     * @param array<string, mixed> $post
     */
    public static function paidAmountFromPost(array $post): ?float
    {
        foreach ([
            'payment_amount',
            'paymentamount',
            'amount',
            'payment',
            'total',
            'sum',
        ] as $key) {
            $v = self::scalar($post, $key);
            if ($v === null || $v === '') {
                continue;
            }
            $num = self::toFloat($v);
            if ($num !== null) {
                return $num;
            }
        }

        if (isset($post['payment']) && is_array($post['payment'])) {
            foreach (['amount', 'total', 'sum'] as $k) {
                if (!isset($post['payment'][$k])) {
                    continue;
                }
                $num = self::toFloat($post['payment'][$k]);
                if ($num !== null) {
                    return $num;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $post
     * @param array<string, mixed> $order
     */
    public static function catalogSubtotalFromPost(array $post, array $order): float
    {
        $sum = 0.0;
        foreach (['prodamount', 'productamount', 'subtotal', 'price_full'] as $key) {
            $v = self::scalar($post, $key);
            if ($v === null) {
                continue;
            }
            $num = self::toFloat($v);
            if ($num !== null && $num > 0) {
                return $num;
            }
        }

        foreach ($order['products'] as $p) {
            if (!is_array($p)) {
                continue;
            }
            $price = self::toFloat($p['price'] ?? 0) ?? 0.0;
            $qty = self::toFloat($p['quantity'] ?? 1) ?? 1.0;
            $sum += $price * max(1.0, $qty);
        }

        return $sum;
    }

    /**
     * @param array<string, mixed> $post
     * @param array<string, mixed> $order
     */
    public static function promocodeFromPost(array $post, array $order): string
    {
        $existing = trim((string)($order['promocode'] ?? ''));
        if ($existing !== '') {
            return $existing;
        }
        foreach (['promocode', 'coupon', 'discountcode', 'discount_code', 'promo'] as $key) {
            $v = self::scalar($post, $key);
            if ($v !== null && trim($v) !== '') {
                return trim($v);
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $post
     */
    public static function isPaidStatusPost(array $post): bool
    {
        foreach (['paymentstatus', 'payment_status', 'status'] as $key) {
            $v = strtolower(trim((string)self::scalar($post, $key) ?? ''));
            if (in_array($v, ['paid', 'оплачен', 'success', 'succeeded', '1', 'yes'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $post
     * @return list<array{name: string, price: float, quantity: float, externalid: string, sku: string}>
     */
    public static function extractProductsFromPost(array $post): array
    {
        if (isset($post['products']) && is_array($post['products'])) {
            return self::normalizeProductList($post['products']);
        }

        $indexed = self::extractIndexedProductRows($post, 'products');
        if ($indexed !== []) {
            return $indexed;
        }

        if (isset($post['payment']) && is_array($post['payment'])) {
            $payment = $post['payment'];
            if (isset($payment['products']) && is_array($payment['products'])) {
                return self::normalizeProductList($payment['products']);
            }
            $fromPayment = self::extractIndexedProductRows($payment, 'products');
            if ($fromPayment !== []) {
                return $fromPayment;
            }
        }

        $name = trim((string)(self::scalar($post, 'product') ?? self::scalar($post, 'goods') ?? ''));
        if ($name !== '') {
            $price = self::toFloat(self::scalar($post, 'price') ?? self::scalar($post, 'prodamount')) ?? 0.0;
            return [[
                'name' => $name,
                'price' => $price,
                'quantity' => 1.0,
                'externalid' => trim((string)(self::scalar($post, 'externalid') ?? self::scalar($post, 'sku') ?? '')),
                'sku' => trim((string)(self::scalar($post, 'sku') ?? '')),
            ]];
        }

        return [];
    }

    /**
     * @param array<string, mixed> $order
     * @param array<string, mixed> $post
     * @param array<string, mixed> $config
     * @return array{name: string, price: float, quantity: float, externalid: string, sku: string}|null
     */
    public static function syntheticProductForFreeOrder(array $order, array $post, array $config): ?array
    {
        $goodsId = self::goodsIdFromHints($post, $config);
        if ($goodsId <= 0) {
            return null;
        }

        $name = self::productNameFromPost($post);
        if ($name === '') {
            $name = 'Gift / promo course #' . $goodsId;
        }

        $catalog = self::catalogSubtotalFromPost($post, $order);
        $price = $catalog > 0 ? $catalog : 1.0;

        return [
            'name' => $name,
            'price' => $price,
            'quantity' => 1.0,
            'externalid' => (string)$goodsId,
            'sku' => '',
        ];
    }

    /**
     * @param array<string, mixed> $post
     * @param array<string, mixed> $config
     */
    public static function goodsIdFromHints(array $post, array $config): int
    {
        foreach (['id_goods', 'goods_id', 'externalid', 'sku'] as $key) {
            $raw = trim((string)(self::scalar($post, $key) ?? ''));
            if ($raw !== '' && ctype_digit($raw)) {
                return (int)$raw;
            }
        }

        $course = strtolower(trim((string)(self::scalar($post, 'course') ?? self::scalar($post, 'COURSE') ?? '')));
        $courseMap = is_array($config['course_slug_goods_map'] ?? null) ? $config['course_slug_goods_map'] : [];
        if ($course !== '' && isset($courseMap[$course]) && is_numeric($courseMap[$course])) {
            return (int)$courseMap[$course];
        }

        $formId = trim((string)(self::scalar($post, 'formid') ?? ''));
        $formMap = is_array($config['form_goods_map'] ?? null) ? $config['form_goods_map'] : [];
        if ($formId !== '' && isset($formMap[$formId]) && is_numeric($formMap[$formId])) {
            return (int)$formMap[$formId];
        }

        $pageId = trim((string)(self::scalar($post, 'pageid') ?? self::scalar($post, 'page_id') ?? ''));
        $pageMap = is_array($config['page_goods_map'] ?? null) ? $config['page_goods_map'] : [];
        if ($pageId !== '' && isset($pageMap[$pageId]) && is_numeric($pageMap[$pageId])) {
            return (int)$pageMap[$pageId];
        }

        $default = $config['default_goods_id'] ?? null;

        return is_numeric($default) ? (int)$default : 0;
    }

    /**
     * @param array<string, mixed> $post
     */
    public static function productNameFromPost(array $post): string
    {
        foreach (['product', 'goods', 'course', 'COURSE', 'title'] as $key) {
            $v = self::scalar($post, $key);
            if ($v !== null && trim($v) !== '') {
                return trim($v);
            }
        }

        return '';
    }

    /**
     * @param array<int|string, mixed> $rows
     * @return list<array{name: string, price: float, quantity: float, externalid: string, sku: string}>
     */
    private static function normalizeProductList(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = trim((string)($row['name'] ?? $row['title'] ?? $row['goods'] ?? ''));
            $price = self::toFloat($row['price'] ?? $row['amount'] ?? 0) ?? 0.0;
            $qty = self::toFloat($row['quantity'] ?? $row['qty'] ?? 1) ?? 1.0;
            if ($name === '' && $price <= 0) {
                continue;
            }
            $out[] = [
                'name' => $name !== '' ? $name : 'Product',
                'price' => $price,
                'quantity' => max(1.0, $qty),
                'externalid' => trim((string)($row['externalid'] ?? $row['external_id'] ?? '')),
                'sku' => trim((string)($row['sku'] ?? '')),
            ];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $bag
     * @return list<array{name: string, price: float, quantity: float, externalid: string, sku: string}>
     */
    private static function extractIndexedProductRows(array $bag, string $prefix): array
    {
        $rows = [];
        foreach ($bag as $key => $value) {
            if (!is_string($key) || !preg_match('/^' . preg_quote($prefix, '/') . '\[(\d+)\]/', $key, $m)) {
                continue;
            }
            $idx = (int)$m[1];
            if (!is_array($value)) {
                continue;
            }
            if (!isset($rows[$idx])) {
                $rows[$idx] = [
                    'name' => '',
                    'price' => 0.0,
                    'quantity' => 1.0,
                    'externalid' => '',
                    'sku' => '',
                ];
            }
            foreach ($value as $field => $fieldVal) {
                $f = strtolower((string)$field);
                if ($f === 'name' || $f === 'title') {
                    $rows[$idx]['name'] = trim((string)$fieldVal);
                } elseif ($f === 'price' || $f === 'amount') {
                    $rows[$idx]['price'] = self::toFloat($fieldVal) ?? 0.0;
                } elseif ($f === 'quantity' || $f === 'qty') {
                    $rows[$idx]['quantity'] = max(1.0, self::toFloat($fieldVal) ?? 1.0);
                } elseif ($f === 'externalid' || $f === 'external_id') {
                    $rows[$idx]['externalid'] = trim((string)$fieldVal);
                } elseif ($f === 'sku') {
                    $rows[$idx]['sku'] = trim((string)$fieldVal);
                }
            }
        }
        if ($rows === []) {
            return [];
        }
        ksort($rows);

        return array_values($rows);
    }

    /**
     * @param array<string, mixed> $post
     */
    private static function scalar(array $post, string $key): ?string
    {
        if (!array_key_exists($key, $post)) {
            $lower = strtolower($key);
            foreach ($post as $k => $v) {
                if (is_string($k) && strtolower($k) === $lower) {
                    $key = $k;
                    break;
                }
            }
        }
        if (!isset($post[$key])) {
            return null;
        }
        $v = $post[$key];
        if (is_scalar($v)) {
            return (string)$v;
        }

        return null;
    }

    private static function toFloat(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float)$value;
        }
        if (!is_string($value)) {
            return null;
        }
        $raw = str_replace([' ', ','], ['', '.'], trim($value));
        if ($raw === '' || !is_numeric($raw)) {
            return null;
        }

        return (float)$raw;
    }
}
