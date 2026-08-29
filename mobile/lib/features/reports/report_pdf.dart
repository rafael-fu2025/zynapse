import 'dart:typed_data';

import 'package:pdf/pdf.dart';
import 'package:pdf/widgets.dart' as pw;

/// ReportPdfGenerator — builds a styled, chart-rich PDF for a report module
/// from the same `GET /reports/{module}` payload the tab already renders.
///
/// Mirrors the web PDF export (ReportPdfView.tsx): brand header, module +
/// date range, KPI tiles, and proportional bar charts. Uses only vector
/// drawing (pw widgets), so no font/asset loading is needed at runtime.
class ReportPdfGenerator {
  ReportPdfGenerator._();

  static const PdfColor _maroon = PdfColor.fromInt(0xFF800000);
  static const PdfColor _ink = PdfColor.fromInt(0xFF1C1917);
  static const PdfColor _muted = PdfColor.fromInt(0xFF6B6562);
  static const PdfColor _line = PdfColor.fromInt(0xFFE6E1DE);
  static const PdfColor _track = PdfColor.fromInt(0xFFF1EFEE);

  static const List<PdfColor> _palette = [
    PdfColor.fromInt(0xFF800000),
    PdfColor.fromInt(0xFFB04545),
    PdfColor.fromInt(0xFF4A8FC1),
    PdfColor.fromInt(0xFF7AA85F),
    PdfColor.fromInt(0xFFD2A13B),
    PdfColor.fromInt(0xFF8F6FB5),
    PdfColor.fromInt(0xFF59A18A),
    PdfColor.fromInt(0xFFC76A93),
  ];

  /// Build the PDF bytes for a module report.
  static Future<Uint8List> build({
    required String module,
    required String start,
    required String end,
    required Map<String, dynamic> data,
  }) async {
    final label = module[0].toUpperCase() + module.substring(1);
    final generated = DateTime.now().toLocal();

    final doc = pw.Document();

    doc.addPage(
      pw.MultiPage(
        pageFormat: PdfPageFormat.a4,
        margin: const pw.EdgeInsets.all(28),
        maxPages: 40,
        header: (context) => pw.Container(
          decoration: const pw.BoxDecoration(
            border:
                pw.Border(bottom: pw.BorderSide(color: _maroon, width: 1.6)),
          ),
          padding: const pw.EdgeInsets.only(bottom: 10),
          child: pw.Row(
            mainAxisAlignment: pw.MainAxisAlignment.spaceBetween,
            children: [
              pw.Column(
                crossAxisAlignment: pw.CrossAxisAlignment.start,
                children: [
                  pw.Text(
                    'SYNAPSE',
                    style: const pw.TextStyle(
                      color: _maroon,
                      fontSize: 20,
                      fontWeight: pw.FontWeight.bold,
                      letterSpacing: 1,
                    ),
                  ),
                  pw.Text(
                    'Reports & analytics',
                    style: const pw.TextStyle(color: _muted, fontSize: 10),
                  ),
                ],
              ),
              pw.Column(
                crossAxisAlignment: pw.CrossAxisAlignment.end,
                children: [
                  pw.Text(
                    label,
                    style: const pw.TextStyle(
                        fontSize: 13, fontWeight: pw.FontWeight.bold),
                  ),
                  pw.Text('$start → $end',
                      style: const pw.TextStyle(color: _muted, fontSize: 9)),
                  pw.Text(
                    'Generated ${generated.month}/${generated.day} ${generated.hour.toString().padLeft(2, '0')}:${generated.minute.toString().padLeft(2, '0')}',
                    style: const pw.TextStyle(color: _muted, fontSize: 8),
                  ),
                ],
              ),
            ],
          ),
        ),
        footer: (context) => pw.Padding(
          padding: const pw.EdgeInsets.only(top: 8),
          child: pw.Text(
            'SYNAPSE analytics export · aggregated, privacy-reviewed data · page ${context.pageNumber}',
            style: const pw.TextStyle(color: _muted, fontSize: 8),
            textAlign: pw.TextAlign.center,
          ),
        ),
        build: (context) => [
          pw.SizedBox(height: 14),
          ..._moduleSections(module, data),
        ],
      ),
    );

    return doc.save();
  }

  static List<pw.Widget> _moduleSections(
      String module, Map<String, dynamic> d) {
    switch (module) {
      case 'clinic':
        return [
          _kpiRow([
            ('Encounters', '${d['total_encounters'] ?? 0}'),
            ('Unique patients', '${d['unique_patients'] ?? 0}'),
            ('Avg / patient', _num(d['avg_visits_per_patient'])),
            ('Avg / day', _num(d['avg_per_day'])),
          ]),
          _breakdown(
            'Status',
            _rows(d, 'status_breakdown', (r) => _cap('${r['status']}')),
          ),
          _breakdown(
            'Complaint categories',
            _rows(d, 'complaint_categories', (r) => '${r['category']}'),
          ),
          _breakdown(
            'Patient type',
            _rows(d, 'patient_type_breakdown', (r) => _cap('${r['kind']}')),
          ),
          _breakdown(
            'Monthly visits',
            _rows(d, 'monthly_visits', (r) => '${r['month']}'),
          ),
          _medTable(
            'Most dispensed medicines',
            d,
            'most_common_medications',
            valueKey: 'qty',
          ),
        ];
      case 'counselling':
        return [
          _kpiRow([
            ('Appointments', '${d['total_appointments'] ?? 0}'),
            ('No-shows', '${d['no_show_count'] ?? 0}'),
            ('No-show rate', '${d['no_show_rate'] ?? 0}%'),
            ('Sessions opened', '${d['sessions_opened'] ?? 0}'),
          ]),
          _breakdown(
            'Status',
            _rows(d, 'status_breakdown', (r) => _cap('${r['status']}')),
          ),
          _breakdown(
            'Type',
            _rows(d, 'type_breakdown', (r) => _cap('${r['type']}')),
          ),
        ];
      case 'inventory':
        return [
          _kpiRow([
            ('Medicines', '${d['total_medicines'] ?? 0}'),
            ('Dispensed', '${d['total_dispensed'] ?? 0}'),
            ('Needs to reorder', '${(d['low_stock'] as List? ?? []).length}'),
            ('Expired', '${(d['expired'] as List? ?? []).length}'),
          ]),
          _medTable('Needs to reorder now', d, 'low_stock',
              valueKey: 'total_stock', showExpiry: true),
          _medTable('Expired batches', d, 'expired',
              valueKey: 'quantity_remaining', showExpiry: true),
          _medTable('Expiring ≤90d', d, 'expiring',
              valueKey: 'quantity_remaining', showExpiry: true),
          _medTable('Top dispensed', d, 'top_dispensed', valueKey: 'qty'),
        ];
      case 'referrals':
        return [
          _kpiRow([
            ('Total', '${d['total_referrals'] ?? 0}'),
            ('Closed', '${d['closed_count'] ?? 0}'),
            ('Closed rate', '${d['closed_rate'] ?? 0}%'),
          ]),
          _breakdown(
            'Status',
            _rows(d, 'status_breakdown', (r) => _cap('${r['status']}')),
          ),
          _breakdown(
            'Direction',
            _rows(
                d,
                'flow_breakdown',
                (r) =>
                    '${_cap('${r['source_module']}')} → ${_cap('${r['target_module']}')}'),
          ),
        ];
      case 'facilities':
        return [
          _kpiRow([
            ('Batches', '${d['total_batches'] ?? 0}'),
            ('Completed', '${d['completed_batches'] ?? 0}'),
            ('Input (kg)', _num(d['input_kg'])),
            ('Output (kg)', _num(d['output_kg'])),
          ]),
          _breakdown(
            'Status',
            _rows(d, 'status_breakdown', (r) => _cap('${r['status']}')),
          ),
          _breakdown(
            'Categories',
            _rows(d, 'category_breakdown', (r) => '${r['category']}'),
          ),
        ];
      default:
        return [
          pw.Text('Unknown module.', style: const pw.TextStyle(color: _muted))
        ];
    }
  }

  static String _num(Object? v) {
    final n = (v as num?)?.toDouble() ?? 0;
    return n == n.roundToDouble() ? n.toInt().toString() : n.toStringAsFixed(2);
  }

  static String _cap(String s) {
    if (s.isEmpty) return s;
    return s[0].toUpperCase() + s.substring(1);
  }

  /// Pull a breakdown list, returning [label, value] tuples.
  static List<(String, num)> _rows(
    Map<String, dynamic> d,
    String key,
    String Function(Map<String, dynamic>) label,
  ) {
    final list = (d[key] as List? ?? []).cast<Map<String, dynamic>>();
    return [
      for (final r in list) (label(r), (r['cnt'] as num?)?.toInt() ?? 0),
    ];
  }

  static pw.Widget _kpiRow(List<(String, String)> tiles) {
    return pw.Row(
      children: [
        for (final (label, value) in tiles)
          pw.Expanded(
            child: pw.Container(
              margin: const pw.EdgeInsets.only(right: 6),
              padding: const pw.EdgeInsets.all(10),
              decoration: pw.BoxDecoration(
                color: PdfColors.white,
                borderRadius: pw.BorderRadius.circular(8),
                border: pw.Border.all(color: _line),
              ),
              child: pw.Column(
                crossAxisAlignment: pw.CrossAxisAlignment.start,
                children: [
                  pw.Text(
                    label.toUpperCase(),
                    style: const pw.TextStyle(
                      color: _muted,
                      fontSize: 7,
                      fontWeight: pw.FontWeight.bold,
                      letterSpacing: 0.4,
                    ),
                  ),
                  pw.SizedBox(height: 3),
                  pw.Text(
                    value,
                    style: const pw.TextStyle(
                      color: _ink,
                      fontSize: 17,
                      fontWeight: pw.FontWeight.bold,
                    ),
                  ),
                ],
              ),
            ),
          ),
      ],
    );
  }

  static pw.Widget _sectionTitle(String title) {
    return pw.Container(
      margin: const pw.EdgeInsets.only(top: 18, bottom: 8),
      padding: const pw.EdgeInsets.only(bottom: 4),
      decoration: const pw.BoxDecoration(
        border: pw.Border(bottom: pw.BorderSide(color: _line)),
      ),
      child: pw.Text(
        title,
        style: const pw.TextStyle(
            fontSize: 12, fontWeight: pw.FontWeight.bold, color: _ink),
      ),
    );
  }

  /// Proportional horizontal bar chart (label + track + value).
  static pw.Widget _breakdown(String title, List<(String, num)> rows) {
    if (rows.isEmpty) return pw.SizedBox.shrink();
    final max = rows.fold<num>(0, (a, r) => r.$2 > a ? r.$2 : a);
    return pw.Column(
      crossAxisAlignment: pw.CrossAxisAlignment.start,
      children: [
        _sectionTitle(title),
        for (final (i, (label, value)) in rows.indexed)
          pw.Padding(
            padding: const pw.EdgeInsets.symmetric(vertical: 2.5),
            child: pw.Row(
              children: [
                pw.SizedBox(
                  width: 130,
                  child: pw.Text(
                    label,
                    overflow: pw.TextOverflow.clip,
                    style: const pw.TextStyle(fontSize: 8, color: _ink),
                  ),
                ),
                pw.Expanded(
                  child: pw.Row(
                    children: [
                      pw.Expanded(
                        flex: max > 0 ? value.toInt() : 0,
                        child: pw.Container(
                          height: 7,
                          decoration: pw.BoxDecoration(
                            color: _palette[i % _palette.length],
                            borderRadius: pw.BorderRadius.circular(3.5),
                          ),
                        ),
                      ),
                      pw.Expanded(
                        flex: max > 0 ? (max - value).toInt() : 1,
                        child: pw.Container(
                          height: 7,
                          decoration: pw.BoxDecoration(
                            color: _track,
                            borderRadius: pw.BorderRadius.circular(3.5),
                          ),
                        ),
                      ),
                    ],
                  ),
                ),
                pw.SizedBox(
                  width: 34,
                  child: pw.Text(
                    '$value',
                    textAlign: pw.TextAlign.right,
                    style: const pw.TextStyle(
                      fontSize: 8,
                      fontWeight: pw.FontWeight.bold,
                      color: _ink,
                    ),
                  ),
                ),
              ],
            ),
          ),
      ],
    );
  }

  /// Ranked medicine list as a table.
  static pw.Widget _medTable(
    String title,
    Map<String, dynamic> d,
    String key, {
    required String valueKey,
    bool showExpiry = false,
  }) {
    final list = (d[key] as List? ?? []).cast<Map<String, dynamic>>();
    if (list.isEmpty) return pw.SizedBox.shrink();
    final rows = <List<dynamic>>[
      for (final r in list)
        [
          '${r['generic_name'] ?? r['name'] ?? '—'}'
              '${r['brand_name'] != null ? ' (${r['brand_name']})' : ''}',
          '${r[valueKey] ?? 0}${r['unit'] != null ? ' ${r['unit']}' : ''}',
          if (showExpiry)
            '${r['batch_number'] ?? ''}'
                '${r['expiration_date'] != null ? ' exp ${r['expiration_date']}' : ''}',
        ],
    ];
    return pw.Column(
      crossAxisAlignment: pw.CrossAxisAlignment.start,
      children: [
        _sectionTitle(title),
        pw.TableHelper.fromTextArray(
          headers: ['Medicine', 'Qty', if (showExpiry) 'Batch / Expiry'],
          data: rows,
          cellStyle: const pw.TextStyle(fontSize: 8, color: _ink),
          headerStyle: const pw.TextStyle(
            fontSize: 8,
            fontWeight: pw.FontWeight.bold,
            color: _maroon,
          ),
          headerDecoration: pw.BoxDecoration(
            color: _track,
            borderRadius: pw.BorderRadius.circular(4),
          ),
          cellPadding:
              const pw.EdgeInsets.symmetric(horizontal: 6, vertical: 4),
          border: pw.TableBorder.all(color: _line, width: 0.6),
          headerAlignments: const {0: pw.Alignment.centerLeft},
        ),
      ],
    );
  }
}
