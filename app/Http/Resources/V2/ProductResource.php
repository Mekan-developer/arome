<?php

namespace App\Http\Resources\V2;

use App\Http\Resources\V2\Concerns\HasServerMeta;
use App\Models\Product;
use App\Models\ProductStock;
use App\Services\V2\Data\CatalogCard;
use App\Services\V2\Data\FieldAccess;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Строка каталога, какой её получает приложение.
 *
 * Закрытое роли поле не обнуляется — ключа в ответе нет вовсе, поэтому его нельзя
 * достать, перехватив трафик.
 *
 * @mixin Product
 */
class ProductResource extends JsonResource
{
    use HasServerMeta;

    public function __construct(Product $product, private readonly FieldAccess $access)
    {
        parent::__construct($product);
    }

    /**
     * Ответ на поднесённый штрихкод: карточка приезжает из сервиса вместе с правами.
     */
    public static function forCard(CatalogCard $card): self
    {
        return new self($card->product, $card->access);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $payload = ['id' => (string) $this->id];

        if ($this->access->sees('mainCode')) {
            $payload['main_code'] = $this->main_code;
        }

        if ($this->access->sees('name')) {
            $payload['name'] = $this->name;
        }

        if ($this->access->sees('sku')) {
            $payload['sku'] = $this->sku;
        }

        if ($this->access->sees('barcode')) {
            $payload['barcode'] = $this->barcode;
        }

        if ($this->access->sees('retail')) {
            $payload['retail'] = $this->money((float) $this->price);
        }

        /* Оптовую цену видит менеджер. У товара её может не быть — тогда ключ не приходит. */
        if ($this->access->sees('wholesale') && $this->wholesale_price !== null) {
            $payload['wholesale'] = $this->money((float) $this->wholesale_price);
        }

        if ($this->access->sees('discount')) {
            $payload['discount'] = (float) $this->discount;
            $payload['final'] = $this->money($this->finalPrice());
        }

        /*
         * Остаток спрашивается у прав целиком (`withStock`), а не по одному ключу
         * матрицы: пока остатки не разбиты по точкам, «остаток по точкам» — это не
         * пустой список, а отсутствующая величина, и ключа в ответе быть не должно.
         */
        if ($this->access->withStock) {
            $payload['stock'] = $this->whenLoaded('stocks', fn (): array => $this->stocks
                ->map(fn (ProductStock $stock): array => [
                    'point_id' => (string) $stock->point_id,
                    'qty' => $stock->qty,
                ])->values()->all(), []);
        }

        $payload['status'] = $this->status;

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function with(Request $request): array
    {
        return ['meta' => $this->serverMeta()];
    }

    /**
     * Деньги уходят целыми копейками: `float` на телефоне однажды покажет 1415.8799.
     *
     * @return array{amount: int, currency: string}
     */
    private function money(float $amount): array
    {
        return ['amount' => (int) round($amount * 100), 'currency' => 'TMT'];
    }
}
