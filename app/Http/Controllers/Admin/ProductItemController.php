<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProductItemController extends Controller
{
    public function index(Request $request): View
    {
        $productId = $request->input('product_id');
        $status = $request->input('status');

        $items = ProductItem::query()
            ->with('product:id,name')
            ->withExists('transactions')
            ->when($request->input('q'), function ($query, $q) {
                $query->where(function ($sub) use ($q) {
                    $sub->whereHas('product', fn ($p) => $p->where('name', 'like', "%{$q}%"))
                        ->orWhere('product_items.name', 'like', "%{$q}%")
                        ->orWhere('product_items.supplier_item_code', 'like', "%{$q}%");
                });
            })
            ->when($productId, fn ($query, $pid) => $query->where('product_items.product_id', $pid))
            ->when($status !== null && $status !== '', fn ($query) => $query->where('product_items.is_active', (bool) $status))
            ->leftJoin('products', 'products.id', '=', 'product_items.product_id')
            ->select('product_items.*')
            ->orderBy('products.name')
            ->orderBy('product_items.sort_order')
            ->orderBy('product_items.sell_price')
            ->paginate(30)
            ->withQueryString();

        $products = Product::query()->orderBy('name')->pluck('name', 'id');
        $selectedProduct = $productId ? Product::find($productId) : null;

        return view('admin.product-items.index', compact('items', 'products', 'selectedProduct'));
    }

    public function create(Request $request): View
    {
        $products = Product::query()->orderBy('name')->pluck('name', 'id');

        return view('admin.product-items.create', [
            'products' => $products,
            'preselectedProduct' => $request->query('product'),
            'existingGroups' => $this->existingGroups(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        ProductItem::create($this->validated($request));

        return redirect()->route('admin.product-items.index')->with('success', 'Item berhasil dibuat.');
    }

    public function edit(ProductItem $productItem): View
    {
        $products = Product::query()->orderBy('name')->pluck('name', 'id');

        return view('admin.product-items.edit', [
            'productItem' => $productItem,
            'products' => $products,
            'existingGroups' => $this->existingGroups(),
        ]);
    }

    public function update(Request $request, ProductItem $productItem): RedirectResponse
    {
        $validated = $this->validated($request);
        $oldBuyPrice = (float) $productItem->buy_price;
        $productItem->update($validated);
        $newBuyPrice = (float) $productItem->buy_price;

        if ($oldBuyPrice !== $newBuyPrice) {
            foreach ($productItem->transactions()->get() as $trx) {
                $discount = (float) ($trx->coupon_discount ?? 0);
                $newProfit = round((float) $trx->amount - $discount - $newBuyPrice, 2);
                $trx->update(['profit' => (string) $newProfit]);
            }
        }

        return redirect()->route('admin.product-items.index')->with('success', 'Item berhasil diperbarui.');
    }

    public function destroy(ProductItem $productItem): RedirectResponse
    {
        if ($productItem->transactions()->exists()) {
            return back()->with('error', 'Item sudah memiliki transaksi terkait dan tidak bisa dihapus. Nonaktifkan saja.');
        }

        $productItem->delete();

        return redirect()->route('admin.product-items.index')->with('success', 'Item berhasil dihapus.');
    }

    /**
     * Hapus banyak item sekaligus dari halaman index.
     *
     * Item yang sudah punya transaksi dilewati, bukan menggagalkan seluruh
     * proses — admin diberi tahu berapa yang terhapus dan mana yang tersisa.
     * Foreign key transaksi memakai restrictOnDelete, jadi pembatasan ini
     * memang wajib.
     */
    public function bulkDestroy(Request $request): RedirectResponse
    {
        $ids = $this->selectedIds($request);

        if ($ids === []) {
            return back()->with('error', 'Tidak ada item yang dipilih.');
        }

        $deletable = ProductItem::query()
            ->whereIn('id', $ids)
            ->whereDoesntHave('transactions')
            ->pluck('id');

        $blocked = count($ids) - $deletable->count();

        ProductItem::query()->whereIn('id', $deletable)->delete();

        return back()->with(
            $blocked > 0 ? 'error' : 'success',
            $this->bulkMessage($deletable->count(), 'dihapus', $blocked)
        );
    }

    /**
     * Aktif/nonaktifkan banyak item sekaligus — jalur aman untuk item yang
     * tidak boleh dihapus karena sudah punya transaksi.
     */
    public function bulkToggleActive(Request $request): RedirectResponse
    {
        $ids = $this->selectedIds($request);

        if ($ids === []) {
            return back()->with('error', 'Tidak ada item yang dipilih.');
        }

        $active = $request->boolean('is_active');

        $affected = ProductItem::query()->whereIn('id', $ids)->update(['is_active' => $active]);

        return back()->with('success', $affected.' item berhasil di'.($active ? 'aktifkan' : 'nonaktifkan').'.');
    }

    /**
     * ID item yang dipilih admin, dibatasi ke item yang benar-benar ada
     * sehingga ID liar dari luar tidak ikut terproses.
     *
     * @return array<int, int>
     */
    protected function selectedIds(Request $request): array
    {
        $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
        ]);

        return ProductItem::query()
            ->whereIn('id', $request->input('ids'))
            ->pluck('id')
            ->all();
    }

    protected function bulkMessage(int $done, string $verb, int $blocked): string
    {
        $message = $done.' item berhasil '.$verb.'.';

        if ($blocked > 0) {
            $message .= ' '.$blocked.' item dilewati karena sudah memiliki transaksi — nonaktifkan saja.';
        }

        return $message;
    }

    /**
     * Nama grup yang sudah dipakai, untuk saran di form admin.
     */
    protected function existingGroups(): array
    {
        return ProductItem::query()
            ->whereNotNull('group')
            ->distinct()
            ->orderBy('group')
            ->pluck('group')
            ->all();
    }

    protected function validated(Request $request): array
    {
        $validated = $request->validate([
            'product_id' => ['required', 'exists:products,id'],
            'name' => ['required', 'string', 'max:150'],
            'group' => ['nullable', 'string', 'max:100'],
            'supplier_item_code' => ['nullable', 'string', 'max:100'],
            'buy_price' => ['required', 'numeric', 'min:0'],
            'sell_price' => ['required', 'numeric', 'min:0'],
            'flash_sale_price' => ['nullable', 'numeric', 'min:0'],
            'flash_sale_start' => ['nullable', 'date'],
            'flash_sale_end' => ['nullable', 'date', 'after_or_equal:flash_sale_start'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['boolean'],
        ]);

        $validated['is_active'] = $request->boolean('is_active');

        // Normalisasi: string kosong dari form dianggap "tanpa grup".
        $validated['group'] = filled($validated['group'] ?? null) ? trim($validated['group']) : null;

        return $validated;
    }
}
