/// Clinic inventory (supplies) — mirrors `frontend/src/schemas/inventory.ts`
/// (`inventoryItemSchema`) and `InventoryItemDto`.
library;

class StockTransaction {
  StockTransaction({
    required this.id,
    required this.type,
    required this.createdAt,
    this.qtyIn,
    this.qtyOut,
    this.stockAfter,
    this.referenceType,
    this.referenceId,
    this.userEmail,
    this.note,
  });

  factory StockTransaction.fromJson(Map<String, dynamic> json) =>
      StockTransaction(
        id: (json['id'] ?? 0) as int,
        type: (json['type'] ?? json['reason_code'] ?? '') as String,
        qtyIn: (json['qty_in'] as num?)?.toInt(),
        qtyOut: (json['qty_out'] as num?)?.toInt(),
        stockAfter: (json['balance_after'] as num?)?.toInt(),
        referenceType: json['reference_type'] as String?,
        referenceId: json['reference_id'] as int?,
        userEmail: json['user_email'] as String?,
        note: json['note'] as String?,
        createdAt: (json['created_at'] ?? '') as String,
      );

  final int id;
  final String type;
  final int? qtyIn;
  final int? qtyOut;
  final int? stockAfter;
  final String? referenceType;
  final int? referenceId;
  final String? userEmail;
  final String? note;
  final String createdAt;
}

class InventoryMovementHint {
  InventoryMovementHint({
    required this.reasonCode,
    required this.qtyDelta,
    required this.createdAt,
    this.userEmail,
  });

  factory InventoryMovementHint.fromJson(Map<String, dynamic>? json) {
    if (json == null) {
      return InventoryMovementHint(
        reasonCode: '',
        qtyDelta: 0,
        createdAt: '',
      );
    }
    return InventoryMovementHint(
      reasonCode: (json['reason_code'] ?? '') as String,
      qtyDelta: (json['qty_delta'] ?? 0) as int,
      createdAt: (json['created_at'] ?? '') as String,
      userEmail: json['user_email'] as String?,
    );
  }

  final String reasonCode;
  final int qtyDelta;
  final String createdAt;
  final String? userEmail;
}

class InventoryItem {
  InventoryItem({
    required this.id,
    required this.sku,
    required this.name,
    required this.unit,
    required this.quantityOnHand,
    required this.reorderLevel,
    this.targetStock,
    required this.stockStatus,
    required this.lowStock,
    required this.archived,
    required this.createdAt,
    this.lastMovement,
  });

  factory InventoryItem.fromJson(Map<String, dynamic> json) => InventoryItem(
        id: json['id'] as int,
        sku: (json['sku'] ?? '') as String,
        name: (json['name'] ?? '') as String,
        unit: (json['unit'] ?? 'pc') as String,
        quantityOnHand: (json['quantity_on_hand'] ?? 0) as int,
        reorderLevel: (json['reorder_level'] ?? 0) as int,
        targetStock: json['target_stock'] as int?,
        stockStatus: (json['stock_status'] ??
            (((json['quantity_on_hand'] ?? 0) as int) <= 0
                ? 'out_of_stock'
                : (((json['quantity_on_hand'] ?? 0) as int) <=
                        ((json['reorder_level'] ?? 0) as int)
                    ? 'needs_to_reorder'
                    : 'in_stock'))) as String,
        lowStock: (json['low_stock'] ?? false) as bool,
        archived: (json['archived'] ?? false) as bool,
        createdAt: (json['created_at'] ?? '') as String,
        lastMovement: InventoryMovementHint.fromJson(
          json['last_movement'] as Map<String, dynamic>?,
        ),
      );

  final int id;
  final String sku;
  final String name;
  final String unit;
  final int quantityOnHand;
  final int reorderLevel;
  final int? targetStock;
  final String stockStatus;
  final bool lowStock;
  final bool archived;
  final String createdAt;

  /// Row-level last-move hint (null when there are no movements yet).
  final InventoryMovementHint? lastMovement;

  String get stockStatusLabel => switch (stockStatus) {
        'out_of_stock' => 'Out of Stock',
        'needs_to_reorder' => 'Needs to Reorder',
        _ => 'In Stock',
      };
}
