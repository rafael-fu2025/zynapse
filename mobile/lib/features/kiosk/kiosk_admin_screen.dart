import 'dart:convert';

import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';

import '../../core/api/api_client.dart';
import '../../core/config.dart';
import '../../core/models/kiosk.dart';
import '../../core/services/api_service.dart';
import '../common/crud_form.dart';

class KioskAdminScreen extends StatefulWidget {
  const KioskAdminScreen({super.key});

  @override
  State<KioskAdminScreen> createState() => _KioskAdminScreenState();
}

class _KioskAdminScreenState extends State<KioskAdminScreen>
    with SingleTickerProviderStateMixin {
  late final TabController _tabs;
  Map<String, dynamic>? _settings;
  int _revision = 0;
  List<KioskMediaAsset> _media = [];
  bool _loading = true;
  bool _saving = false;
  bool _uploading = false;
  double _uploadProgress = 0;
  String _uploadLabel = '';
  String? _error;
  String _kindFilter = 'all';
  String _archiveFilter = 'active';
  final _search = TextEditingController();

  @override
  void initState() {
    super.initState();
    _tabs = TabController(length: 3, vsync: this);
    _load();
  }

  @override
  void dispose() {
    _tabs.dispose();
    _search.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final results = await Future.wait<dynamic>([
        ApiService.I.kioskSettings(),
        ApiService.I.kioskMedia(),
      ]);
      if (!mounted) return;
      final snapshot = results[0] as KioskSettingsSnapshot;
      setState(() {
        _settings = _clone(snapshot.settings);
        _revision = snapshot.revision;
        _media = results[1] as List<KioskMediaAsset>;
        _loading = false;
      });
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _error = mapDioError(error).message;
        _loading = false;
      });
    }
  }

  static Map<String, dynamic> _clone(Map<String, dynamic> value) =>
      jsonDecode(jsonEncode(value)) as Map<String, dynamic>;

  Map<String, dynamic> get _display =>
      _settings!['display'] as Map<String, dynamic>;
  List<Map<String, dynamic>> get _playlist =>
      (_settings!['playlist'] as List).cast<Map<String, dynamic>>();

  void _setDisplay(String key, Object value) {
    setState(() => _display[key] = value);
  }

  Future<void> _save() async {
    if (_saving || _settings == null) return;
    setState(() => _saving = true);
    try {
      final snapshot =
          await ApiService.I.updateKioskSettings(_settings!, _revision);
      if (!mounted) return;
      setState(() {
        _settings = _clone(snapshot.settings);
        _revision = snapshot.revision;
      });
      showCrudMessage(context, 'Kiosk settings saved for every device.');
    } catch (error) {
      if (!mounted) return;
      showCrudMessage(context, mapDioError(error).message, error: true);
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  Future<void> _pickAndUpload() async {
    if (_uploading) return;
    List<XFile> files;
    try {
      files = await ImagePicker().pickMultipleMedia(requestFullMetadata: false);
    } catch (error) {
      if (mounted) showCrudMessage(context, '$error', error: true);
      return;
    }
    if (files.isEmpty || !mounted) return;
    final sizes = await Future.wait(files.map((file) => file.length()));
    final total =
        sizes.fold<int>(0, (sum, size) => sum + size).clamp(1, 1 << 62);
    var completed = 0;
    var succeeded = 0;
    final failures = <String>[];
    setState(() {
      _uploading = true;
      _uploadProgress = 0;
    });
    for (var index = 0; index < files.length; index++) {
      final file = files[index];
      if (!mounted) break;
      setState(() => _uploadLabel =
          'Uploading ${file.name} (${index + 1} of ${files.length})');
      try {
        final bytes = kIsWeb ? await file.readAsBytes() : null;
        final asset = await ApiService.I.uploadKioskMedia(
          filename: file.name,
          path: kIsWeb ? null : file.path,
          bytes: bytes,
          onProgress: (sent, _) {
            if (mounted) {
              setState(() => _uploadProgress = (completed + sent) / total);
            }
          },
        );
        _media.insert(0, asset);
        succeeded++;
      } catch (error) {
        failures.add('${file.name}: ${mapDioError(error).message}');
      }
      completed += sizes[index];
      if (mounted) setState(() => _uploadProgress = completed / total);
    }
    if (!mounted) return;
    setState(() {
      _uploading = false;
      _uploadLabel = '';
    });
    if (failures.isEmpty) {
      showCrudMessage(context, '$succeeded media file(s) uploaded.');
    } else {
      showCrudMessage(
        context,
        '$succeeded uploaded; ${failures.length} failed. ${failures.first}',
        error: true,
      );
    }
  }

  List<KioskMediaAsset> get _filteredMedia {
    final query = _search.text.trim().toLowerCase();
    return _media.where((asset) {
      final kind = _kindFilter == 'all' || asset.kind == _kindFilter;
      final archived = _archiveFilter == 'all' ||
          (_archiveFilter == 'archived' ? asset.archived : !asset.archived);
      final search = query.isEmpty ||
          asset.label.toLowerCase().contains(query) ||
          asset.originalName.toLowerCase().contains(query);
      return kind && archived && search;
    }).toList();
  }

  void _addToPlaylist(KioskMediaAsset asset) {
    final order = _playlist.length;
    final id = 'mobile-${DateTime.now().microsecondsSinceEpoch}-${asset.id}';
    final base = <String, dynamic>{
      'id': id,
      'type': asset.kind,
      'label': asset.label,
      'enabled': true,
      'order': order,
      'source': asset.url,
    };
    if (asset.kind == 'photo') {
      base.addAll({
        'durationMs': 10000,
        'alt': asset.label,
        'decorative': false,
        'fit': 'contain',
        'focalPosition': 'center',
        'background': 'black',
      });
    } else {
      base.addAll({
        'poster': asset.thumbnailUrl,
        'fit': 'contain',
        'muted': true,
        'loop': false,
        'playbackRate': 1,
        'useNaturalDuration': true,
        'maxDurationMs': 60000,
      });
    }
    setState(() => _playlist.add(base));
    showCrudMessage(context, '${asset.label} added. Save to apply.');
    _tabs.animateTo(2);
  }

  Future<void> _archive(KioskMediaAsset asset) async {
    final ok = await runCrudAction(
      context,
      () async {
        final updated =
            await ApiService.I.setKioskMediaArchived(asset.id, !asset.archived);
        final index = _media.indexWhere((item) => item.id == asset.id);
        if (index >= 0) _media[index] = updated;
      },
      successMessage: asset.archived ? 'Media restored.' : 'Media archived.',
    );
    if (ok && mounted) setState(() {});
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Kiosk administration'),
        bottom: TabBar(
          controller: _tabs,
          tabs: const [
            Tab(text: 'Display'),
            Tab(text: 'Gallery'),
            Tab(text: 'Playlist'),
          ],
        ),
        actions: [
          IconButton(
            tooltip: 'Save shared settings',
            onPressed: _saving || _loading ? null : _save,
            icon: _saving
                ? const SizedBox.square(
                    dimension: 20,
                    child: CircularProgressIndicator(strokeWidth: 2),
                  )
                : const Icon(Icons.save_outlined),
          ),
        ],
      ),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : _error != null
              ? Center(
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Text(_error!),
                      const SizedBox(height: 12),
                      FilledButton(
                          onPressed: _load, child: const Text('Retry')),
                    ],
                  ),
                )
              : TabBarView(
                  controller: _tabs,
                  children: [_displayTab(), _galleryTab(), _playlistTab()],
                ),
    );
  }

  Widget _displayTab() => ListView(
        padding: const EdgeInsets.all(16),
        children: [
          Text('Shared kiosk settings',
              style: Theme.of(context).textTheme.titleLarge),
          const SizedBox(height: 4),
          const Text('Changes apply to the web lobby display after Save.'),
          const SizedBox(height: 12),
          SwitchListTile(
            title: const Text('Enable call chime'),
            value: (_settings!['enabled'] ?? true) as bool,
            onChanged: (value) => setState(() => _settings!['enabled'] = value),
          ),
          SwitchListTile(
            title: const Text('Enable media panel'),
            value: (_display['mediaEnabled'] ?? true) as bool,
            onChanged: (value) => _setDisplay('mediaEnabled', value),
          ),
          SwitchListTile(
            title: const Text('Show media captions'),
            value: (_display['showCaptions'] ?? true) as bool,
            onChanged: (value) => _setDisplay('showCaptions', value),
          ),
          SwitchListTile(
            title: const Text('Show playlist progress'),
            value: (_display['showProgress'] ?? true) as bool,
            onChanged: (value) => _setDisplay('showProgress', value),
          ),
          SwitchListTile(
            title: const Text('Auto-scroll waiting list'),
            value: (_display['autoScroll'] ?? false) as bool,
            onChanged: (value) => _setDisplay('autoScroll', value),
          ),
          const SizedBox(height: 12),
          FilledButton.icon(
            onPressed: _saving ? null : _save,
            icon: const Icon(Icons.cloud_upload_outlined),
            label: Text('Save shared settings · revision $_revision'),
          ),
        ],
      );

  Widget _galleryTab() => Column(
        children: [
          Padding(
            padding: const EdgeInsets.all(12),
            child: Column(
              children: [
                SizedBox(
                  width: double.infinity,
                  child: FilledButton.icon(
                    onPressed: _uploading ? null : _pickAndUpload,
                    icon: const Icon(Icons.upload_file_outlined),
                    label: const Text('Choose photos or videos'),
                  ),
                ),
                if (_uploading) ...[
                  const SizedBox(height: 8),
                  LinearProgressIndicator(value: _uploadProgress),
                  const SizedBox(height: 4),
                  Text('$_uploadLabel · ${(_uploadProgress * 100).round()}%'),
                ],
                const SizedBox(height: 10),
                TextField(
                  controller: _search,
                  onChanged: (_) => setState(() {}),
                  decoration: const InputDecoration(
                    prefixIcon: Icon(Icons.search),
                    labelText: 'Search gallery',
                    border: OutlineInputBorder(),
                  ),
                ),
                const SizedBox(height: 8),
                Row(
                  children: [
                    Expanded(
                        child: _filter(
                            'Type',
                            _kindFilter,
                            const ['all', 'photo', 'video'],
                            (v) => setState(() => _kindFilter = v))),
                    const SizedBox(width: 8),
                    Expanded(
                        child: _filter(
                            'Status',
                            _archiveFilter,
                            const ['active', 'archived', 'all'],
                            (v) => setState(() => _archiveFilter = v))),
                  ],
                ),
              ],
            ),
          ),
          Expanded(
            child: RefreshIndicator(
              onRefresh: _load,
              child: _filteredMedia.isEmpty
                  ? ListView(children: const [
                      Padding(
                          padding: EdgeInsets.all(32),
                          child: Center(
                              child: Text('No media matches these filters.')))
                    ])
                  : GridView.builder(
                      padding: const EdgeInsets.fromLTRB(12, 0, 12, 20),
                      gridDelegate:
                          const SliverGridDelegateWithFixedCrossAxisCount(
                              crossAxisCount: 2,
                              childAspectRatio: .72,
                              crossAxisSpacing: 10,
                              mainAxisSpacing: 10),
                      itemCount: _filteredMedia.length,
                      itemBuilder: (context, index) =>
                          _mediaCard(_filteredMedia[index]),
                    ),
            ),
          ),
        ],
      );

  Widget _filter(String label, String value, List<String> values,
          ValueChanged<String> changed) =>
      DropdownButtonFormField<String>(
          initialValue: value,
          decoration: InputDecoration(
              labelText: label,
              border: const OutlineInputBorder(),
              isDense: true),
          items: values
              .map((item) => DropdownMenuItem(value: item, child: Text(item)))
              .toList(),
          onChanged: (value) {
            if (value != null) changed(value);
          });

  Widget _mediaCard(KioskMediaAsset asset) => Card(
        clipBehavior: Clip.antiAlias,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Expanded(
              child: ColoredBox(
                color: Colors.black,
                child: Image.network(
                  AppConfig.absoluteUrl(asset.thumbnailUrl),
                  fit: BoxFit.contain,
                  errorBuilder: (_, __, ___) => const Center(
                      child: Icon(Icons.broken_image_outlined,
                          color: Colors.white54)),
                ),
              ),
            ),
            Padding(
              padding: const EdgeInsets.all(8),
              child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(asset.label,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(fontWeight: FontWeight.w700)),
                    Text('${asset.kind} · ${_bytes(asset.sizeBytes)}',
                        style: Theme.of(context).textTheme.bodySmall),
                    const SizedBox(height: 4),
                    Row(children: [
                      Expanded(
                          child: FilledButton(
                              onPressed: asset.archived
                                  ? null
                                  : () => _addToPlaylist(asset),
                              child: const Text('Add'))),
                      IconButton(
                          tooltip: asset.archived ? 'Restore' : 'Archive',
                          onPressed: () => _archive(asset),
                          icon: Icon(asset.archived
                              ? Icons.restore
                              : Icons.archive_outlined)),
                    ]),
                  ]),
            ),
          ],
        ),
      );

  Widget _playlistTab() => ReorderableListView.builder(
        padding: const EdgeInsets.all(12),
        header: Padding(
          padding: const EdgeInsets.only(bottom: 10),
          child: Text(
              'Drag to reorder. Video loop and original-duration controls are available on each item.',
              style: Theme.of(context).textTheme.bodySmall),
        ),
        itemCount: _playlist.length,
        onReorderItem: (oldIndex, newIndex) {
          setState(() {
            final item = _playlist.removeAt(oldIndex);
            _playlist.insert(newIndex, item);
            for (var i = 0; i < _playlist.length; i++) {
              _playlist[i]['order'] = i;
            }
          });
        },
        itemBuilder: (context, index) {
          final item = _playlist[index];
          final video = item['type'] == 'video';
          return Card(
            key: ValueKey(item['id']),
            child: ExpansionTile(
              leading: ReorderableDragStartListener(
                  index: index, child: const Icon(Icons.drag_handle)),
              title: Text((item['label'] ?? 'Playlist item') as String),
              subtitle: Text(
                  '${item['type']} · ${(item['enabled'] ?? true) ? 'active' : 'disabled'}'),
              trailing: IconButton(
                tooltip: 'Remove',
                icon: const Icon(Icons.delete_outline),
                onPressed: () => setState(() {
                  _playlist.removeAt(index);
                  for (var i = 0; i < _playlist.length; i++) {
                    _playlist[i]['order'] = i;
                  }
                }),
              ),
              children: [
                SwitchListTile(
                    title: const Text('Enabled'),
                    value: (item['enabled'] ?? true) as bool,
                    onChanged: (value) =>
                        setState(() => item['enabled'] = value)),
                if (video) ...[
                  SwitchListTile(
                      title: const Text('Use original file duration'),
                      value: (item['useNaturalDuration'] ?? true) as bool,
                      onChanged: (value) =>
                          setState(() => item['useNaturalDuration'] = value)),
                  SwitchListTile(
                      title: const Text('Loop video'),
                      value: (item['loop'] ?? false) as bool,
                      onChanged: (value) =>
                          setState(() => item['loop'] = value)),
                ],
              ],
            ),
          );
        },
      );

  static String _bytes(int value) => value >= 1024 * 1024
      ? '${(value / (1024 * 1024)).toStringAsFixed(1)} MB'
      : '${(value / 1024).ceil()} KB';
}
