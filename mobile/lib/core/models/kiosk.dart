class KioskMediaAsset {
  KioskMediaAsset({
    required this.id,
    required this.publicId,
    required this.kind,
    required this.label,
    required this.originalName,
    required this.mimeType,
    required this.sizeBytes,
    required this.url,
    required this.thumbnailUrl,
    required this.archived,
    required this.createdAt,
  });

  factory KioskMediaAsset.fromJson(Map<String, dynamic> json) =>
      KioskMediaAsset(
        id: (json['id'] ?? 0) as int,
        publicId: (json['public_id'] ?? '') as String,
        kind: (json['kind'] ?? '') as String,
        label: (json['label'] ?? '') as String,
        originalName: (json['original_name'] ?? '') as String,
        mimeType: (json['mime_type'] ?? '') as String,
        sizeBytes: (json['size_bytes'] as num?)?.toInt() ?? 0,
        url: (json['url'] ?? '') as String,
        thumbnailUrl: (json['thumbnail_url'] ?? '') as String,
        archived: (json['archived'] ?? false) as bool,
        createdAt: (json['created_at'] ?? '') as String,
      );

  final int id;
  final String publicId;
  final String kind;
  final String label;
  final String originalName;
  final String mimeType;
  final int sizeBytes;
  final String url;
  final String thumbnailUrl;
  final bool archived;
  final String createdAt;
}

class KioskSettingsSnapshot {
  KioskSettingsSnapshot({
    required this.settings,
    required this.revision,
    this.updatedAt,
  });

  factory KioskSettingsSnapshot.fromJson(Map<String, dynamic> json) =>
      KioskSettingsSnapshot(
        settings: Map<String, dynamic>.from(
          json['settings'] as Map<String, dynamic>? ?? const {},
        ),
        revision: (json['revision'] ?? 0) as int,
        updatedAt: json['updated_at'] as String?,
      );

  final Map<String, dynamic> settings;
  final int revision;
  final String? updatedAt;
}
