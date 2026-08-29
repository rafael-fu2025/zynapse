/// Medicines catalogue — mirrors `frontend/src/schemas/medicines.ts`
/// (`medicineSchema` / `medicineLastMovementSchema`) and `MedicineDto`.
library;

class MedicineLastMovement {
  MedicineLastMovement({
    required this.type,
    required this.quantity,
    required this.createdAt,
    this.userEmail,
  });

  factory MedicineLastMovement.fromJson(Map<String, dynamic>? json) {
    if (json == null) {
      return MedicineLastMovement(type: '', quantity: 0, createdAt: '');
    }
    return MedicineLastMovement(
      type: (json['type'] ?? '') as String,
      quantity: (json['quantity'] ?? 0) as int,
      createdAt: (json['created_at'] ?? '') as String,
      userEmail: json['user_email'] as String?,
    );
  }

  /// received | dispensed | expired | adjusted | returned | recalled.
  final String type;
  final int quantity;
  final String createdAt;
  final String? userEmail;
}

class Medicine {
  Medicine({
    required this.id,
    required this.genericName,
    this.brandName,
    this.category,
    this.dosageForm,
    this.dosageStrength,
    required this.unit,
    required this.reorderThreshold,
    this.targetStock,
    required this.stockStatus,
    this.description,
    required this.quantityOnHand,
    required this.lowStock,
    this.earliestExpiry,
    required this.archived,
    required this.createdAt,
    this.lastMovement,
  });

  factory Medicine.fromJson(Map<String, dynamic> json) => Medicine(
        id: json['id'] as int,
        genericName: (json['generic_name'] ?? '') as String,
        brandName: json['brand_name'] as String?,
        category: json['category'] as String?,
        dosageForm: json['dosage_form'] as String?,
        dosageStrength: json['dosage_strength'] as String?,
        unit: (json['unit'] ?? 'pc') as String,
        reorderThreshold: (json['reorder_threshold'] ?? 0) as int,
        targetStock: json['target_stock'] as int?,
        stockStatus: (json['stock_status'] ??
            (((json['quantity_on_hand'] ?? 0) as int) <= 0
                ? 'out_of_stock'
                : (((json['quantity_on_hand'] ?? 0) as int) <=
                        ((json['reorder_threshold'] ?? 0) as int)
                    ? 'needs_to_reorder'
                    : 'in_stock'))) as String,
        description: json['description'] as String?,
        quantityOnHand: (json['quantity_on_hand'] ?? 0) as int,
        lowStock: (json['low_stock'] ?? false) as bool,
        earliestExpiry: json['earliest_expiry'] as String?,
        archived: (json['archived'] ?? false) as bool,
        createdAt: (json['created_at'] ?? '') as String,
        lastMovement: MedicineLastMovement.fromJson(
          json['last_movement'] as Map<String, dynamic>?,
        ),
      );

  final int id;
  final String genericName;
  final String? brandName;
  final String? category;
  final String? dosageForm;
  final String? dosageStrength;
  final String unit;
  final int reorderThreshold;
  final int? targetStock;
  final String stockStatus;
  final String? description;
  final int quantityOnHand;
  final bool lowStock;
  final String? earliestExpiry;
  final bool archived;
  final String createdAt;
  final MedicineLastMovement? lastMovement;

  String get displayName => brandName != null && brandName!.isNotEmpty
      ? '$genericName ($brandName)'
      : genericName;

  String get stockStatusLabel => switch (stockStatus) {
        'out_of_stock' => 'Out of Stock',
        'needs_to_reorder' => 'Needs to Reorder',
        _ => 'In Stock',
      };
}
