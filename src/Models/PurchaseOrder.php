<?php

namespace BadChoice\Mojito\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchaseOrder extends Model {

    use SoftDeletes;

    protected $table    = "purchase_orders";
    protected $guarded  = ['id'];
    protected $appends  = ['vendorName', 'contentsArray'];
    protected $hidden   = ['vendor', 'contents'];

    public static function canBeDeleted($id){
        return true;
    }

    public static function createWith($vendor_id, $items, $status = PurchaseOrderContent::STATUS_PENDING){
        if( ! count($items) ) return null;

        return tap(PurchaseOrder::create( compact('vendor_id', 'status') ), function ($order) use ($items) {
            return $order->contents()->createMany(collect($items)->map(function ($item) use ($order) {
                return (new PurchaseOrderContent([
                    'status'         => $order->status,
                    'price'          => $item->costPrice,
                    'quantity'       => $item->quantity,
                    'item_vendor_id' => $item->pivot_id,
                ]))->makeHidden(['itemName', 'itemBarcode']);
            })->toArray());
        });
    }

    public static function updateWith($order, $items, $status = PurchaseOrderContent::STATUS_PENDING) {
        if( ! count($items) ) return null;

        $order->update(compact("status"));
        $order->contents()->whereNotIn('id', collect($items)->pluck('id'))->delete();
        collect($items)->each(function ($item) use ($order) {
            PurchaseOrderContent::updateOrCreate([
                'id'             => $item->id,
                'order_id'       => $order->id,
                'item_vendor_id' => $item->pivot_id,
            ], [
                'status'   => $order->status,
                'price'    => $item->costPrice,
                'quantity' => $item->quantity,
            ]);
        });
        return $order;
    }

    //============================================================================
    // RELATIONSHIPS
    //============================================================================
    public function vendor(){
        return $this->belongsTo(Vendor::class);
    }

    public function contents(){
        return $this->hasMany(PurchaseOrderContent::class, 'order_id');
    }

    //============================================================================
    // SCOPES
    //============================================================================
    public function scopeByStatus($query, $status){
        return $query->where('status', '=', $status);
    }

    public function scopeActive($query){
        return $query->where    ('status', '=', PurchaseOrderContent::STATUS_PENDING)
                     ->orWhere  ('status', '=', PurchaseOrderContent::STATUS_PARTIAL_RECEIVED);
    }

    //============================================================================
    // JSON ATTRIBUTES
    //============================================================================
    public function getVendorNameAttribute(){
        return ($this->vendor) ? $this->vendor->name : "Vendor Deleted";
    }
    public function getContentsArrayAttribute(){
        return $this->contents;
    }

    //============================================================================
    // METHODS
    //============================================================================
    /**
     * Called for purchaseOrderContent, when it is updated, it updates the orderStatus
     * @return int
     */
    public function calculateTotal(){
        return $this->contents->sum(function ($content) {
            return $content->quantity * $content->price;
        });
    }

    /**
     * Called for purchaseOrderContent, when it is updated, it updates the orderStatus
     * @return int
     */
    public function calculateStatus(){
        $total          = $this->contents->sum('quantity');
        $received       = $this->contents->sum('received');
        $leftToReceive  = $total - $received;

        if ( $this->status == PurchaseOrderContent::STATUS_DRAFT )  return PurchaseOrderContent::STATUS_DRAFT;
        else if ($leftToReceive == 0)                               return PurchaseOrderContent::STATUS_RECEIVED;
        else if ($leftToReceive == $total)                          return PurchaseOrderContent::STATUS_PENDING;
        return PurchaseOrderContent::STATUS_PARTIAL_RECEIVED;
    }

    public function updateStatus() {
        $this->update(["status" => $this->calculateStatus()]);
    }

    public function statusName(){
        return PurchaseOrderContent::getStatusName($this->status);
    }

    public function receiveAll($warehouse_id){
        $this->contents->each(function ($content) use ($warehouse_id) {
            $content->receive($content->quantity - $content->received, $warehouse_id);
        });
    }
}