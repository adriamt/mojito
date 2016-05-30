<?php namespace BadChoice\Mojito\Traits;
use DB;
use BadChoice\Mojito\Models\Unit;
trait ItemTrait{

    //=============================================================================
    // SCOPES
    //=============================================================================
    public function scopeWithStockManagement($query){
        $usesStockManagementKey = config('mojito.usesStockManagementKey');
        return $query->where($usesStockManagementKey ,'=',1);
    }

    public function scopeWithStockManagementAndNoAssembly($query){
        $usesStockManagementKey = config('mojito.usesStockManagementKey');
        return $query->where($usesStockManagementKey ,'=',1)->doesntHave('assembliesForScope');
    }

    //=============================================================================
    // RELATIONSHIPS
    //=============================================================================
    public function warehouses(){
        return $this->belongsToMany(config('mojito.warehouseClass'),'stocks','item_id','warehouse_id');
    }

    public function stocks(){
        return $this->hasMany(config('mojito.stockClass'),'item_id');
    }

    public function unit(){
        return $this->belongsTo(Unit::class);
    }

    //=============================================================================
    // ASSEMBLIES
    //=============================================================================
    /**
     * @return All the assemblies
     */
    public function assemblies(){
        return $this->belongsToMany(config('mojito.itemClass','Item'),config('mojito.assembliesTable','assemblies'),'main_item_id','item_id')->withPivot('quantity','id','unit_id','deleted_at')->withTimestamps()->wherePivot('deleted_at','=',null);
    }

    /**
     * Same function as assemblies but without all the Pivot related data (so we can use it for scopes)
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function assembliesForScope(){
        return $this->belongsToMany(config('mojito.itemClass','Item'),config('mojito.assembliesTable','assemblies'),'main_item_id','item_id')->withTimestamps()->whereNull('menu_item_inventory.deleted_at');
    }

    /**
     * @return mixed the Items where this item is assembled to
     */
    public function assembledTo(){
        return $this->belongsToMany(config('mojito.itemClass','Item'),config('mojito.assembliesTable','assemblies'),'item_id','main_item_id')->withPivot('quantity','id','unit_id','deleted_at')->withTimestamps()->wherePivot('deleted_at','=',null);
    }

    public function assemblyPrice(){
        $price = 0;
        foreach($this->assemblies as $assembly){
            $price+= $assembly->costPrice * $assembly->pivot->quantity;
        }
        return $price;
    }

    public function hasAssemblies(){
        return count($this->assemblies) > 0;
    }

    //=============================================================================
    // STOCK
    //=============================================================================
    /**
     * Sums the stock for each warehouse so it returns total stock for item
     * @return float total stock
     */
    public function calculateStock(){
        $stockClass = config('mojito.stockClass','Stock');
        $stock      = $stockClass::byItem($this->id)->groupBy('item_id')->select(DB::raw('sum(quantity) as quantity'))->first();
        if($stock) return $stock->quantity;
        else return 0;
    }

    /**
     * Decrease item stock, if it has assembly items, the item itself is not decreased
     * @param $qty the quantity to decrease, if quantity is <0 the items will be added
     * @param $warehouse the warehouse were we are decreasing the inventory (if it is there)
     * @param $weight 1 the weight that is discounted if item uses weight
     * @param int $unit_id the unit id if other than the item itself
     */
    public function decreaseStock($qty, $warehouse, $weight = 1, $unit_id = null){

        $usesStockManagementKey = config('mojito.usesStockManagementKey');
        if($unit_id == null) $unit_id = $this->unit_id;

        if($warehouse != null && $this->$usesStockManagementKey && $qty != 0) {
            if($this->usesWeight){
                $qty *= $weight;
            }

            if(count($this->assemblies) > 0){
                foreach ($this->assemblies as $assembledItem) {
                    if ($assembledItem->$usesStockManagementKey) {
                        if( $warehouse->stockByItem($assembledItem) ) { //If item is in warehouse
                            $warehouse->add($assembledItem->id, -($qty * $assembledItem->pivot->quantity), $assembledItem->pivot->unit_id);
                        }
                    }
                }
            }
            else{
                if( $warehouse->stockByItem($this) ) { //If item is in warehouse
                    $warehouse->add($this->id, -$qty, $unit_id);
                }
            }
        }
    }

    public static function syncStock($fromDate, $warehouse_id = null){
        $stockClass         = config('mojito.stockClass','Stock');
        $inventorySynced    = $stockClass::sync($fromDate);
        $synced             = [];

        if($inventorySynced["new"] != null) {
            foreach ($inventorySynced["new"] as $object) {
                if ($object["warehouse_id"] == $warehouse_id)
                    $synced[] = ["id" => $object["item_id"], "inventory" => $object["quantity"], "alert" => $object["alert"]];
            }
        }
        if($inventorySynced["updated"] != null) {
            foreach ($inventorySynced["updated"] as $object) {
                if ($object["warehouse_id"] == $warehouse_id)
                    $synced[] = ["id" => $object["item_id"], "inventory" => $object["quantity"], "alert" => $object["alert"]];
            }
        }
        if($inventorySynced["deleted"] != null) {
            foreach ($inventorySynced["deleted"] as $object) {
                if ($object["warehouse_id"] == $warehouse_id)
                    $synced[] = ["id" => $object["item_id"], "inventory" => 0, "alert" => $object["alert"]];
            }
        }

        return $synced;
    }

    //=============================================================================
    // DELETE
    //=============================================================================
    public function deleteStockRelations(){
        $stockClass = config('mojito.stockClass','Stock');
        foreach ($stockClass::byItem($this->id)->get() as $object) {
            $object->delete();
        }

        foreach (\BadChoice\Mojito\Models\Assembly::byMainItem($this->id)->get() as $object){
            $object->delete();
        }

        foreach (\BadChoice\Mojito\Models\VendorItemPivot::byItem($this->id)->get() as $object){
            $object->delete();
        }
    }
}