import 'package:flutter/material.dart';
import 'package:hugeicons/hugeicons.dart';
import 'package:image_picker/image_picker.dart';

import '../../core/api/api_client.dart';
import '../../core/models/carousel_slide.dart';
import '../../core/services/api_service.dart';
import '../common/crud_form.dart';
import '../common/widgets.dart';

/// Admin CRUD for the login-page carousel. Mirrors `AdminCarouselPage`.
///
/// Mobile references existing server-relative image paths rather than
/// uploading bytes: the API accepts a path when no file is present, so a
/// slide can be added/edited without a second image pipeline. Web remains
/// the place to upload brand-new images.
class AdminCarouselScreen extends StatefulWidget {
  const AdminCarouselScreen({super.key});

  @override
  State<AdminCarouselScreen> createState() => _AdminCarouselScreenState();
}

class _AdminCarouselScreenState extends State<AdminCarouselScreen> {
  List<CarouselSlide> _items = const [];
  bool _loading = true;
  bool _busy = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = _items.isEmpty;
      _error = null;
    });
    try {
      final slides = await ApiService.I.adminCarouselSlides();
      if (!mounted) return;
      setState(() {
        _items = slides;
        _loading = false;
      });
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _error = mapDioError(e).message;
        _loading = false;
      });
    }
  }

  Future<void> _edit([CarouselSlide? slide]) async {
    final isEdit = slide != null;
    final payload = await showCrudForm(
      context,
      title: isEdit ? 'Edit slide' : 'New carousel slide',
      fields: [
        CrudField.text(
          'title',
          'Title',
          required: false,
          initial: slide?.title,
          maxLength: 150,
        ),
        CrudField.text(
          'subtitle',
          'Subtitle',
          required: false,
          initial: slide?.subtitle,
          maxLength: 255,
        ),
        CrudField.text(
          'light_image',
          'Light image path',
          required: !isEdit,
          hint: 'assets/img/brand/... or uploads/carousel/...',
          initial: slide?.lightImage,
          maxLength: 255,
        ),
        CrudField.text(
          'dark_image',
          'Dark image path (optional)',
          required: false,
          hint: 'assets/img/brand/... or uploads/carousel/...',
          initial: slide?.darkImage,
          maxLength: 255,
        ),
        CrudField.dropdown(
          'fit_mode',
          'Fit mode',
          const ['cover', 'contain_blur'],
          initial: slide?.fitMode ?? 'cover',
        ),
        CrudField.bool(
          'is_active',
          'Active on the login screen',
          boolInitial: slide?.isActive ?? true,
        ),
      ],
      submitLabel: isEdit ? 'Save' : 'Create',
    );
    if (payload == null || !mounted) return;
    final body = <String, dynamic>{
      if (payload['title'] != null) 'title': payload['title'],
      if (payload['subtitle'] != null) 'subtitle': payload['subtitle'],
      if (payload['light_image'] != null)
        'light_image': payload['light_image'],
      if (payload['dark_image'] != null) 'dark_image': payload['dark_image'],
      'fit_mode': payload['fit_mode'],
      'is_active': payload['is_active'] == true ? '1' : '0',
    };
    final ok = await runCrudAction(
      context,
      () => isEdit
          ? ApiService.I.updateCarouselSlide(slide.id, body)
          : ApiService.I.createCarouselSlide(body),
      successMessage: isEdit ? 'Slide updated.' : 'Slide created.',
    );
    if (ok) _load();
  }

  Future<void> _toggle(CarouselSlide slide) async {
    setState(() => _busy = true);
    try {
      await ApiService.I.toggleCarouselSlide(slide.id);
      if (!mounted) return;
      await _load();
    } catch (e) {
      if (mounted) _snack(mapDioError(e).message, error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _uploadNew() async {
    final picker = ImagePicker();
    XFile? light;
    try {
      light = await picker.pickImage(source: ImageSource.gallery);
    } catch (e) {
      _snack('Could not open the image picker on this device.', error: true);
      return;
    }
    if (light == null || !mounted) return;
    final payload = await showCrudForm(
      context,
      title: 'Upload carousel slide',
      fields: const [
        CrudField.text('title', 'Title', required: false, maxLength: 150),
        CrudField.text('subtitle', 'Subtitle',
            required: false, maxLength: 255),
        CrudField.dropdown(
          'fit_mode',
          'Fit mode',
          ['cover', 'contain_blur'],
          initial: 'cover',
        ),
        CrudField.bool('is_active', 'Active on the login screen',
            boolInitial: true),
      ],
      submitLabel: 'Upload',
    );
    if (payload == null || !mounted) return;
    setState(() => _busy = true);
    try {
      await ApiService.I.uploadCarouselSlide(
        fields: {
          if (payload['title'] != null) 'title': '${payload['title']}',
          if (payload['subtitle'] != null)
            'subtitle': '${payload['subtitle']}',
          'fit_mode': '${payload['fit_mode']}',
          'is_active': payload['is_active'] == true ? '1' : '0',
        },
        lightImagePath: light.path,
      );
      if (!mounted) return;
      _snack('Slide uploaded.');
      await _load();
    } catch (e) {
      _snack(mapDioError(e).message, error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _replaceImage(CarouselSlide slide, {required bool dark}) async {
    final picker = ImagePicker();
    XFile? picked;
    try {
      picked = await picker.pickImage(source: ImageSource.gallery);
    } catch (e) {
      _snack('Could not open the image picker on this device.', error: true);
      return;
    }
    if (picked == null || !mounted) return;
    setState(() => _busy = true);
    try {
      await ApiService.I.uploadCarouselSlide(
        id: slide.id,
        fields: {
          if (slide.title != null) 'title': slide.title!,
          if (slide.subtitle != null) 'subtitle': slide.subtitle!,
          'fit_mode': slide.fitMode,
          'is_active': slide.isActive ? '1' : '0',
        },
        lightImagePath: dark ? null : picked.path,
        darkImagePath: dark ? picked.path : null,
      );
      if (!mounted) return;
      _snack(dark ? 'Dark image replaced.' : 'Light image replaced.');
      await _load();
    } catch (e) {
      _snack(mapDioError(e).message, error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _delete(CarouselSlide slide) async {
    final confirmed = await showCrudConfirm(
      context,
      title: 'Delete slide?',
      message: 'Delete “${slide.title ?? 'slide #${slide.id}'}”? '
          'The image file is retained.',
      confirmLabel: 'Delete',
      destructive: true,
    );
    if (!confirmed || !mounted) return;
    final ok = await runCrudAction(
      context,
      () => ApiService.I.deleteCarouselSlide(slide.id),
      successMessage: 'Slide deleted.',
    );
    if (ok) _load();
  }

  Future<void> _move(int index, int direction) async {
    final target = index + direction;
    if (target < 0 || target >= _items.length || _busy) return;
    final reordered = [..._items];
    final moved = reordered.removeAt(index);
    reordered.insert(target, moved);
    setState(() {
      _busy = true;
      _items = reordered;
    });
    try {
      await ApiService.I.reorderCarouselSlides(
        reordered.map((slide) => slide.id).toList(),
      );
      if (!mounted) return;
      await _load();
    } catch (e) {
      if (mounted) {
        _snack(mapDioError(e).message, error: true);
        await _load();
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  void _snack(String message, {bool error = false}) {
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(message),
        backgroundColor: error ? const Color(0xFFB3261E) : null,
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Login carousel'),
        actions: [
          IconButton(
            onPressed: _busy ? null : _uploadNew,
            tooltip: 'Upload slide',
            icon: const Icon(HugeIcons.strokeRoundedDownload02),
          ),
          IconButton(
            onPressed: _load,
            tooltip: 'Refresh',
            icon: const Icon(HugeIcons.strokeRoundedRefresh),
          ),
        ],
      ),
      floatingActionButton: FloatingActionButton.extended(
        backgroundColor: const Color(0xFF800000),
        foregroundColor: Colors.white,
        onPressed: _busy ? null : () => _edit(),
        icon: const Icon(HugeIcons.strokeRoundedAdd01),
        label: const Text('Add slide'),
      ),
      body: _buildBody(),
    );
  }

  Widget _buildBody() {
    if (_loading) return AsyncState.loading();
    if (_error != null) return AsyncState.error(_error!, onRetry: _load);
    if (_items.isEmpty) {
      return AsyncState.empty('No carousel slides yet.');
    }
    return RefreshIndicator(
      onRefresh: _load,
      child: ListView.separated(
        padding: const EdgeInsets.fromLTRB(16, 16, 16, 90),
        itemCount: _items.length,
        separatorBuilder: (_, __) => const SizedBox(height: 8),
        itemBuilder: (context, i) {
          final slide = _items[i];
          return Card(
            elevation: 0,
            margin: EdgeInsets.zero,
            shape: RoundedRectangleBorder(
              borderRadius: BorderRadius.circular(12),
              side: BorderSide(
                color: Theme.of(context)
                    .colorScheme
                    .outlineVariant
                    .withValues(alpha: 0.5),
              ),
            ),
            child: Padding(
              padding: const EdgeInsets.all(10),
              child: Row(
                children: [
                  ClipRRect(
                    borderRadius: BorderRadius.circular(8),
                    child: SizedBox(
                      width: 84,
                      height: 56,
                      child: Image.network(
                        slide.imageUrl,
                        fit: BoxFit.cover,
                        errorBuilder: (_, __, ___) => Container(
                          color: const Color(0xFFE7E5E4),
                          child: const Icon(
                            HugeIcons.strokeRoundedFile01,
                            size: 20,
                            color: Colors.black38,
                          ),
                        ),
                      ),
                    ),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          slide.title ?? 'Slide #${slide.id}',
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: const TextStyle(
                            fontWeight: FontWeight.w700,
                          ),
                        ),
                        if ((slide.subtitle ?? '').isNotEmpty)
                          Text(
                            slide.subtitle!,
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: const TextStyle(
                              fontSize: 11.5,
                              color: Colors.black54,
                            ),
                          ),
                        const SizedBox(height: 4),
                        Wrap(
                          spacing: 6,
                          children: [
                            StatusBadge(
                              label: slide.isActive ? 'Active' : 'Hidden',
                              color: slide.isActive
                                  ? const Color(0xFF1B7A43)
                                  : Colors.black45,
                            ),
                            StatusBadge(
                              label: titleCaseOption(slide.fitMode),
                              color: const Color(0xFF1E6FD9),
                            ),
                          ],
                        ),
                      ],
                    ),
                  ),
                  Column(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      IconButton(
                        onPressed: _busy ? null : () => _move(i, -1),
                        tooltip: 'Move up',
                        icon: const Icon(Icons.keyboard_arrow_up, size: 20),
                      ),
                      IconButton(
                        onPressed: _busy ? null : () => _move(i, 1),
                        tooltip: 'Move down',
                        icon: const Icon(Icons.keyboard_arrow_down, size: 20),
                      ),
                    ],
                  ),
                  PopupMenuButton<String>(
                    enabled: !_busy,
                    icon: const Icon(HugeIcons.strokeRoundedMore),
                    onSelected: (action) {
                      switch (action) {
                        case 'edit':
                          _edit(slide);
                        case 'toggle':
                          _toggle(slide);
                        case 'light':
                          _replaceImage(slide, dark: false);
                        case 'dark':
                          _replaceImage(slide, dark: true);
                        case 'delete':
                          _delete(slide);
                      }
                    },
                    itemBuilder: (_) => [
                      const PopupMenuItem(
                          value: 'edit', child: Text('Edit slide')),
                      const PopupMenuItem(
                          value: 'light', child: Text('Replace light image')),
                      const PopupMenuItem(
                          value: 'dark', child: Text('Replace dark image')),
                      PopupMenuItem(
                        value: 'toggle',
                        child: Text(
                          slide.isActive ? 'Hide from login' : 'Show on login',
                        ),
                      ),
                      const PopupMenuItem(
                          value: 'delete', child: Text('Delete slide')),
                    ],
                  ),
                ],
              ),
            ),
          );
        },
      ),
    );
  }
}
