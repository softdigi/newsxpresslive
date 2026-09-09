import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../../core/constants/app_colors.dart';
import '../../../providers/agency_provider.dart';

class AgencySubmitScreen extends StatefulWidget {
  const AgencySubmitScreen({super.key});

  @override
  State<AgencySubmitScreen> createState() => _AgencySubmitScreenState();
}

class _AgencySubmitScreenState extends State<AgencySubmitScreen> {
  final _formKey = GlobalKey<FormState>();
  final _titleCtrl = TextEditingController();
  final _contentCtrl = TextEditingController();
  final _summaryCtrl = TextEditingController();
  final _imageUrlCtrl = TextEditingController();
  final _tagsCtrl = TextEditingController();
  final _sourceUrlCtrl = TextEditingController();

  String _category = 'General';
  String _language = 'en';
  DateTime? _publishedDate;
  List<String> _tags = [];

  static const _categories = [
    'General', 'Politics', 'Business', 'Technology',
    'Sports', 'Entertainment', 'Science', 'Health',
  ];

  static const _languages = [
    'en', 'hi', 'ta', 'te', 'mr', 'bn', 'gu', 'kn', 'ml', 'pa',
  ];

  @override
  void dispose() {
    _titleCtrl.dispose();
    _contentCtrl.dispose();
    _summaryCtrl.dispose();
    _imageUrlCtrl.dispose();
    _tagsCtrl.dispose();
    _sourceUrlCtrl.dispose();
    super.dispose();
  }

  Future<void> _pickDate() async {
    final d = await showDatePicker(
      context: context,
      initialDate: _publishedDate ?? DateTime.now(),
      firstDate: DateTime(2010),
      lastDate: DateTime.now().add(const Duration(days: 1)),
    );
    if (d != null) setState(() => _publishedDate = d);
  }

  void _addTag() {
    final raw = _tagsCtrl.text.trim();
    if (raw.isEmpty) return;
    final newTags =
        raw.split(',').map((t) => t.trim()).where((t) => t.isNotEmpty).toList();
    setState(() {
      _tags = {..._tags, ...newTags}.toList();
      _tagsCtrl.clear();
    });
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;
    final data = {
      'title': _titleCtrl.text.trim(),
      'content': _contentCtrl.text.trim(),
      'summary': _summaryCtrl.text.trim(),
      'category': _category,
      'language': _language,
      'image_url': _imageUrlCtrl.text.trim(),
      'tags': _tags.join(','),
      'source_url': _sourceUrlCtrl.text.trim(),
      if (_publishedDate != null)
        'published_at': _publishedDate!.toIso8601String(),
    };
    final ok = await context.read<AgencyProvider>().submitArticle(data);
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(ok ? 'Article submitted successfully!' : 'Submission failed'),
        backgroundColor: ok ? Colors.green : AppColors.primary,
      ),
    );
    if (ok) Navigator.pop(context);
  }

  String? _urlValidator(String? v) {
    if (v == null || v.isEmpty) return null;
    final uri = Uri.tryParse(v);
    if (uri == null || !uri.hasScheme) return 'Enter a valid URL';
    return null;
  }

  @override
  Widget build(BuildContext context) {
    return Consumer<AgencyProvider>(
      builder: (context, provider, _) {
        return Scaffold(
          backgroundColor: AppColors.scaffoldLight,
          appBar: AppBar(
            backgroundColor: AppColors.primary,
            foregroundColor: Colors.white,
            title: const Text('Submit Article'),
          ),
          body: Form(
            key: _formKey,
            child: ListView(
              padding: const EdgeInsets.all(16),
              children: [
                _card(
                  child: Column(
                    children: [
                      _field(
                        controller: _titleCtrl,
                        label: 'Title *',
                        hint: 'Article headline (min 10 chars)',
                        validator: (v) {
                          if (v == null || v.trim().length < 10) {
                            return 'Title must be at least 10 characters';
                          }
                          return null;
                        },
                      ),
                      const SizedBox(height: 14),
                      _field(
                        controller: _contentCtrl,
                        label: 'Content *',
                        hint: 'Full article content (min 100 chars)',
                        maxLines: 6,
                        validator: (v) {
                          if (v == null || v.trim().length < 100) {
                            return 'Content must be at least 100 characters';
                          }
                          return null;
                        },
                      ),
                      const SizedBox(height: 14),
                      _field(
                        controller: _summaryCtrl,
                        label: 'Summary',
                        hint: 'Brief summary (optional)',
                        maxLines: 2,
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: 12),
                _card(
                  child: Column(
                    children: [
                      _dropdownField<String>(
                        label: 'Category',
                        value: _category,
                        items: _categories,
                        onChanged: (v) => setState(() => _category = v!),
                      ),
                      const SizedBox(height: 14),
                      _dropdownField<String>(
                        label: 'Language',
                        value: _language,
                        items: _languages,
                        onChanged: (v) => setState(() => _language = v!),
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: 12),
                _card(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      _field(
                        controller: _imageUrlCtrl,
                        label: 'Image URL',
                        hint: 'https://example.com/image.jpg',
                        validator: _urlValidator,
                        keyboardType: TextInputType.url,
                      ),
                      if (_imageUrlCtrl.text.isNotEmpty) ...[
                        const SizedBox(height: 8),
                        ClipRRect(
                          borderRadius: BorderRadius.circular(8),
                          child: Image.network(
                            _imageUrlCtrl.text,
                            height: 120,
                            width: double.infinity,
                            fit: BoxFit.cover,
                            errorBuilder: (_, __, ___) => Container(
                              height: 120,
                              color: AppColors.shimmerBase,
                              child: const Center(
                                  child: Icon(Icons.broken_image_outlined)),
                            ),
                          ),
                        ),
                      ],
                    ],
                  ),
                ),
                const SizedBox(height: 12),
                _card(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        children: [
                          Expanded(
                            child: _field(
                              controller: _tagsCtrl,
                              label: 'Tags (comma-separated)',
                              hint: 'politics, economy',
                            ),
                          ),
                          const SizedBox(width: 8),
                          IconButton(
                            onPressed: _addTag,
                            icon: const Icon(Icons.add_circle,
                                color: AppColors.primary),
                          ),
                        ],
                      ),
                      if (_tags.isNotEmpty) ...[
                        const SizedBox(height: 8),
                        Wrap(
                          spacing: 6,
                          children: _tags
                              .map((t) => Chip(
                                    label: Text(t,
                                        style:
                                            const TextStyle(fontSize: 12)),
                                    deleteIcon: const Icon(Icons.close,
                                        size: 14),
                                    onDeleted: () =>
                                        setState(() => _tags.remove(t)),
                                    backgroundColor: AppColors.chipBackground,
                                    labelStyle: const TextStyle(
                                        color: AppColors.chipText),
                                  ))
                              .toList(),
                        ),
                      ],
                    ],
                  ),
                ),
                const SizedBox(height: 12),
                _card(
                  child: Column(
                    children: [
                      _field(
                        controller: _sourceUrlCtrl,
                        label: 'Source URL',
                        hint: 'https://original-source.com/article',
                        validator: _urlValidator,
                        keyboardType: TextInputType.url,
                      ),
                      const SizedBox(height: 14),
                      GestureDetector(
                        onTap: _pickDate,
                        child: Container(
                          padding: const EdgeInsets.symmetric(
                              horizontal: 12, vertical: 14),
                          decoration: BoxDecoration(
                            border: Border.all(color: AppColors.divider),
                            borderRadius: BorderRadius.circular(10),
                          ),
                          child: Row(
                            children: [
                              const Icon(Icons.calendar_today_outlined,
                                  size: 18,
                                  color: AppColors.textSecondaryLight),
                              const SizedBox(width: 10),
                              Text(
                                _publishedDate != null
                                    ? _publishedDate!
                                        .toString()
                                        .split(' ')
                                        .first
                                    : 'Published Date (optional)',
                                style: TextStyle(
                                    color: _publishedDate != null
                                        ? AppColors.textPrimaryLight
                                        : AppColors.textSecondaryLight),
                              ),
                            ],
                          ),
                        ),
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: 20),
                SizedBox(
                  height: 50,
                  child: ElevatedButton(
                    onPressed: provider.isLoading ? null : _submit,
                    style: ElevatedButton.styleFrom(
                      backgroundColor: AppColors.primary,
                      foregroundColor: Colors.white,
                      shape: RoundedRectangleBorder(
                          borderRadius: BorderRadius.circular(12)),
                    ),
                    child: provider.isLoading
                        ? const SizedBox(
                            height: 20,
                            width: 20,
                            child: CircularProgressIndicator(
                                color: Colors.white, strokeWidth: 2),
                          )
                        : const Text('Submit Article',
                            style: TextStyle(fontSize: 16)),
                  ),
                ),
                const SizedBox(height: 24),
              ],
            ),
          ),
        );
      },
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

  Widget _field({
    required TextEditingController controller,
    required String label,
    String? hint,
    int maxLines = 1,
    String? Function(String?)? validator,
    TextInputType? keyboardType,
  }) {
    return TextFormField(
      controller: controller,
      maxLines: maxLines,
      keyboardType: keyboardType,
      decoration: InputDecoration(
        labelText: label,
        hintText: hint,
        border: OutlineInputBorder(
            borderRadius: BorderRadius.circular(10)),
        contentPadding:
            const EdgeInsets.symmetric(horizontal: 12, vertical: 12),
      ),
      validator: validator,
      onChanged: (_) => setState(() {}),
    );
  }

  Widget _dropdownField<T>({
    required String label,
    required T value,
    required List<T> items,
    required void Function(T?) onChanged,
  }) {
    return DropdownButtonFormField<T>(
      value: value,
      decoration: InputDecoration(
        labelText: label,
        border:
            OutlineInputBorder(borderRadius: BorderRadius.circular(10)),
        contentPadding:
            const EdgeInsets.symmetric(horizontal: 12, vertical: 12),
      ),
      items: items
          .map((i) => DropdownMenuItem<T>(
                value: i,
                child: Text(i.toString()),
              ))
          .toList(),
      onChanged: onChanged,
    );
  }
}
