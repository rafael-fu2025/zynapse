/// One procurement request — the row of `GET /clinic/reorders` (backend
/// `ReorderDto`, web ReordersTab). Lifecycle: pending → approved → ordered
/// ("Purchased") → received ("Delivered") → completed; `cancelled` is
/// reachable from the first three. There is at most one open request per
/// item, so the list doubles as the per-item procurement queue.
class Reorder {
  Reorder({
    required this.id,
    required this.itemType,
    required this.requestedQuantity,
    required this.currentStock,
    required this.reorderLevel,
    required this.urgency,
    required this.status,
    required this.autoTriggered,
    required this.createdAt,
    this.medicineId,
    this.supplyItemId,
    this.itemName,
    this.genericName,
    this.unit,
    this.targetStock,
    this.procurementNote,
    this.orderDate,
    this.expectedDeliveryDate,
    this.actualDeliveryDate,
    this.fulfilledAt,
  });

  factory Reorder.fromJson(Map<String, dynamic> json) => Reorder(
        id: (json['id'] ?? 0) as int,
        itemType: (json['item_type'] ?? 'medicine') as String,
        medicineId: json['medicine_id'] as int?,
        supplyItemId: json['supply_item_id'] as int?,
        itemName: json['item_name'] as String?,
        genericName: json['generic_name'] as String?,
        unit: json['unit'] as String?,
        requestedQuantity: (json['requested_quantity'] ?? 0) as int,
        currentStock: (json['current_stock'] ?? 0) as int,
        reorderLevel: (json['reorder_level'] ?? 0) as int,
        targetStock: json['target_stock'] as int?,
        urgency: (json['urgency'] ?? 'medium') as String,
        status: (json['status'] ?? 'pending') as String,
        autoTriggered: (json['auto_triggered'] ?? false) as bool,
        procurementNote: json['procurement_note'] as String?,
        orderDate: json['order_date'] as String?,
        expectedDeliveryDate: json['expected_delivery_date'] as String?,
        actualDeliveryDate: json['actual_delivery_date'] as String?,
        fulfilledAt: json['fulfilled_at'] as String?,
        createdAt: (json['created_at'] ?? '') as String,
      );

  final int id;

  /// `medicine` | `supply` — which catalog the request feeds.
  final String itemType;
  final int? medicineId;
  final int? supplyItemId;

  /// Display name joined from the medicines/supplies tables.
  final String? itemName;
  final String? genericName;
  final String? unit;
  final int requestedQuantity;
  final int currentStock;
  final int reorderLevel;
  final int? targetStock;

  /// `low` | `medium` | `high` | `critical`.
  final String urgency;
  final String status;

  /// True when the reorder auto-check filed this request.
  final bool autoTriggered;
  final String? procurementNote;
  final String? orderDate;
  final String? expectedDeliveryDate;
  final String? actualDeliveryDate;
  final String? fulfilledAt;
  final String createdAt;

  bool get isOpen =>
      status == 'pending' || status == 'approved' || status == 'ordered';

  /// `Ordered`/`Delivered` display labels follow the web's
  /// REORDER_STATUS_LABEL (constants.ts) — raw statuses say `ordered`/
  /// `received`, the procurement vocabulary says Purchased/Delivered.
  String get statusLabel => switch (status) {
        'pending' => 'Pending',
        'approved' => 'Approved',
        'ordered' => 'Purchased',
        'received' => 'Delivered',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
        _ => status,
      };

  String get itemLabel => itemName ?? genericName ?? '';
}
