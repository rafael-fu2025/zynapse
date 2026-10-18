/// Login carousel slide — mirrors `frontend/src/schemas/carousel.ts`.
library;

import '../config.dart';

class CarouselSlide {
  CarouselSlide({
    required this.id,
    this.title,
    this.subtitle,
    required this.lightImage,
    this.darkImage,
    required this.fitMode,
    required this.sortOrder,
    required this.isActive,
  });

  factory CarouselSlide.fromJson(Map<String, dynamic> json) => CarouselSlide(
        id: int.tryParse('${json['id'] ?? 0}') ?? 0,
        title: json['title'] as String?,
        subtitle: json['subtitle'] as String?,
        lightImage: (json['light_image'] ?? '') as String,
        darkImage: (json['dark_image'] as String?)?.trim().isEmpty == true
            ? null
            : json['dark_image'] as String?,
        fitMode: (json['fit_mode'] ?? 'cover') as String,
        sortOrder: int.tryParse('${json['sort_order'] ?? 0}') ?? 0,
        isActive:
            int.tryParse('${json['is_active'] ?? 0}') == 1 ||
                json['is_active'] == true,
      );

  final int id;
  final String? title;
  final String? subtitle;
  final String lightImage;
  final String? darkImage;

  /// cover | contain_blur.
  final String fitMode;
  final int sortOrder;
  final bool isActive;

  /// The public image path is server-relative (`uploads/carousel/...`).
  /// Resolve it against the API origin, not the `/api/v1` base path.
  String get imageUrl => _publicUrl(lightImage);

  String get darkImageUrl =>
      darkImage == null ? imageUrl : _publicUrl(darkImage!);

  static String _publicUrl(String path) {
    if (path.startsWith('http://') || path.startsWith('https://')) return path;
    final base = Uri.parse(AppConfig.apiBaseUrl);
    final relative = path.replaceFirst(RegExp(r'^/+'), '');
    return base
        .replace(path: '/$relative', query: null, fragment: null)
        .toString();
  }
}
