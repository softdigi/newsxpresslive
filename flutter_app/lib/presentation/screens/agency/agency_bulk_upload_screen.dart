import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';

import '../../../core/constants/app_colors.dart';
import '../../../providers/agency_provider.dart';

class AgencyBulkUploadScreen extends StatefulWidget {
  const AgencyBulkUploadScreen({super.key});

  @override
  State<AgencyBulkUploadScreen> createState() =>
      _AgencyBulkUploadScreenState();
}

class _AgencyBulkUploadScreenState extends State<AgencyBulkUploadScreen> {
  final _pathCtrl = TextEditingController();
  bool _uploading = false;
  double _progress = 0;
  Map<String, dynamic>? _result;
  final List<String> _errorRows = [];

  static const _templateColumns =
      'title,content,summary,category,language,image_url,tags,source_url,published_at';

  void _showTemplate() {
    showDialog(
      context: context,
      builder: (_) => AlertDialog(
        title: const Text('CSV Template Columns'),
        content: SelectableText(_templateColumns),
        actions: [
          TextButton(
            onPressed: () {
              Clipboard.setData(
                  const ClipboardData(text: _templateColumns));
              Navigator.pop(context);
              ScaffoldMessenger.of(context).showSnackBar(
                const SnackBar(content: Text('Columns copied to clipboard')),
              );
            },
            child: const Text('Copy'),
          ),
          TextButton(
            onPressed: () => Navigator.pop(context),
            child: const Text('Close'),
          ),
        ],
      ),
    );
  }

  Future<void> _upload() async {
    final path = _pathCtrl.text.trim();
    if (path.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Please enter the file path')),
      );
      return;
    }

    setState(() {
      _uploading = true;
      _progress = 0;
      _result = null;
      _errorRows.clear();
    });

    // Simulate progress
    for (int i = 1; i <= 5; i++) {
      await Future<void>.delayed(const Duration(milliseconds: 200));
      setState(() => _progress = i / 5);
    }

    final result = await context.read<AgencyProvider>().uploadCsv(path);
    setState(() {
      _uploading = false;
      _result = result;
      if (result == null) {
        _errorRows.add(
            context.read<AgencyProvider>().errorMsg ?? 'Upload failed');
      }
    });
  }

  void _downloadErrorReport() {
    if (_errorRows.isEmpty) return;
    Clipboard.setData(ClipboardData(text: _errorRows.join('\n')));
    ScaffoldMessenger.of(context).showSnackBar(
      const SnackBar(content: Text('Error report copied to clipboard')),
    );
  }

  @override
  void dispose() {
    _pathCtrl.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.scaffoldLight,
      appBar: AppBar(
        backgroundColor: AppColors.primary,
        foregroundColor: Colors.white,
        title: const Text('Bulk Upload'),
      ),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          // Template
          _card(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const Text('CSV Template',
                    style: TextStyle(
                        fontSize: 15,
                        fontWeight: FontWeight.bold,
                        color: AppColors.textPrimaryLight)),
                const SizedBox(height: 8),
                const Text(
                    'Download the template to prepare your articles for bulk upload.',
                    style: TextStyle(color: AppColors.textSecondaryLight,
                        fontSize: 13)),
                const SizedBox(height: 12),
                OutlinedButton.icon(
                  onPressed: _showTemplate,
                  icon: const Icon(Icons.download_outlined),
                  label: const Text('Download CSV Template'),
                  style: OutlinedButton.styleFrom(
                    foregroundColor: AppColors.primary,
                    side: const BorderSide(color: AppColors.primary),
                    shape: RoundedRectangleBorder(
                        borderRadius: BorderRadius.circular(10)),
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(height: 12),
          // File path input
          _card(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const Text('Upload CSV',
                    style: TextStyle(
                        fontSize: 15,
                        fontWeight: FontWeight.bold,
                        color: AppColors.textPrimaryLight)),
                const SizedBox(height: 8),
                const Text(
                  '📁 Note: Add file_picker to pubspec.yaml for a native file picker. '
                  'For now, enter the absolute file path manually.',
                  style: TextStyle(
                      color: AppColors.accent,
                      fontSize: 12),
                ),
                const SizedBox(height: 12),
                TextField(
                  controller: _pathCtrl,
                  decoration: InputDecoration(
                    labelText: 'CSV File Path',
                    hintText: '/storage/emulated/0/articles.csv',
                    prefixIcon: const Icon(Icons.folder_outlined),
                    border: OutlineInputBorder(
                        borderRadius: BorderRadius.circular(10)),
                    contentPadding: const EdgeInsets.symmetric(
                        horizontal: 12, vertical: 12),
                  ),
                ),
                const SizedBox(height: 12),
                if (_uploading) ...[
                  LinearProgressIndicator(
                    value: _progress,
                    backgroundColor: AppColors.shimmerBase,
                    color: AppColors.primary,
                    minHeight: 6,
                  ),
                  const SizedBox(height: 8),
                  Text(
                      'Uploading… ${(_progress * 100).toInt()}%',
                      style: const TextStyle(
                          color: AppColors.textSecondaryLight,
                          fontSize: 12)),
                  const SizedBox(height: 8),
                ],
                SizedBox(
                  width: double.infinity,
                  child: ElevatedButton.icon(
                    onPressed: _uploading ? null : _upload,
                    icon: const Icon(Icons.upload),
                    label: const Text('Upload CSV'),
                    style: ElevatedButton.styleFrom(
                      backgroundColor: AppColors.primary,
                      foregroundColor: Colors.white,
                      shape: RoundedRectangleBorder(
                          borderRadius: BorderRadius.circular(10)),
                    ),
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(height: 12),
          // Result summary
          if (_result != null) ...[
            _card(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const Text('Upload Result',
                      style: TextStyle(
                          fontSize: 15,
                          fontWeight: FontWeight.bold,
                          color: AppColors.textPrimaryLight)),
                  const SizedBox(height: 12),
                  _resultRow('Total', _result!['total'].toString(),
                      AppColors.textPrimaryLight),
                  _resultRow('Successful',
                      _result!['validCount'].toString(), Colors.green),
                  _resultRow('Failed',
                      _result!['invalidCount'].toString(), AppColors.primary),
                ],
              ),
            ),
          ],
          // Error rows
          if (_errorRows.isNotEmpty) ...[
            const SizedBox(height: 12),
            _card(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      const Text('Error Rows',
                          style: TextStyle(
                              fontSize: 15,
                              fontWeight: FontWeight.bold,
                              color: AppColors.primary)),
                      const Spacer(),
                      TextButton.icon(
                        onPressed: _downloadErrorReport,
                        icon: const Icon(Icons.copy, size: 14),
                        label: const Text('Copy Report',
                            style: TextStyle(fontSize: 12)),
                        style: TextButton.styleFrom(
                            foregroundColor: AppColors.primary),
                      ),
                    ],
                  ),
                  const SizedBox(height: 8),
                  ..._errorRows.map(
                    (e) => Padding(
                      padding: const EdgeInsets.only(bottom: 4),
                      child: Row(
                        children: [
                          const Icon(Icons.error_outline,
                              size: 14, color: AppColors.primary),
                          const SizedBox(width: 6),
                          Expanded(
                            child: Text(e,
                                style: const TextStyle(
                                    fontSize: 12,
                                    color: AppColors.textSecondaryLight)),
                          ),
                        ],
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ],
        ],
      ),
    );
  }

  Widget _resultRow(String label, String value, Color color) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 8),
      child: Row(
        children: [
          Text('$label: ',
              style: const TextStyle(
                  color: AppColors.textSecondaryLight, fontSize: 13)),
          Text(value,
              style: TextStyle(
                  color: color,
                  fontWeight: FontWeight.bold,
                  fontSize: 15)),
        ],
      ),
    );
  }

  Widget _card({required Widget child}) {
    return Container(
      decoration: BoxDecoration(
        color: AppColors.cardLight,
        borderRadius: BorderRadius.circular(12),
        boxShadow: [
          BoxShadow(
              color: Colors.black.withOpacity(0.05),
              blurRadius: 6,
              offset: const Offset(0, 2))
        ],
      ),
      padding: const EdgeInsets.all(16),
      child: child,
    );
  }
}
