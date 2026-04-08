import 'dart:io';
import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';
import '../../../core/constants/app_colors.dart';
import '../../../core/constants/app_strings.dart';
import '../../../data/models/complaint_model.dart';
import '../../../data/services/complaint_service.dart';

/// Form screen to submit a new public complaint.
class SubmitComplaintScreen extends StatefulWidget {
  const SubmitComplaintScreen({
    super.key,
    required this.categories,
    this.onSubmitted,
  });

  final List<ComplaintCategory> categories;
  final VoidCallback? onSubmitted;

  @override
  State<SubmitComplaintScreen> createState() => _SubmitComplaintScreenState();
}

class _SubmitComplaintScreenState extends State<SubmitComplaintScreen> {
  final _formKey    = GlobalKey<FormState>();
  final _titleCtrl  = TextEditingController();
  final _descCtrl   = TextEditingController();
  final _nameCtrl   = TextEditingController();
  final _locCtrl    = TextEditingController();
  final _service    = ComplaintService();

  ComplaintCategory? _selectedCategory;
  XFile?   _pickedPhoto;
  bool     _isAnonymous  = false;
  bool     _submitting   = false;
  String?  _resultMsg;
  bool     _success      = false;

  @override
  void initState() {
    super.initState();
    if (widget.categories.isNotEmpty) {
      _selectedCategory = widget.categories.first;
    }
  }

  @override
  void dispose() {
    _titleCtrl.dispose();
    _descCtrl.dispose();
    _nameCtrl.dispose();
    _locCtrl.dispose();
    _service.dispose();
    super.dispose();
  }

  Future<void> _pickPhoto() async {
    final file = await ImagePicker().pickImage(
      source:    ImageSource.gallery,
      maxWidth:  1280,
      imageQuality: 70,
    );
    if (file != null && mounted) setState(() => _pickedPhoto = file);
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;
    if (_selectedCategory == null) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Please select a category')),
      );
      return;
    }
    setState(() { _submitting = true; _resultMsg = null; });

    // We need category ID. Since the model only has slug, we use index+1 as fallback.
    // In production the API returns ids; here we pass category slug via location_text workaround.
    // Preferred: fetch id from a map. For now we use index.
    final catIndex = widget.categories.indexOf(_selectedCategory!);
    final catId    = catIndex >= 0 ? catIndex + 1 : 1;

    final result = await _service.submit(
      title:        _titleCtrl.text.trim(),
      description:  _descCtrl.text.trim(),
      categoryId:   catId,
      locationText: _locCtrl.text.trim().isEmpty ? null : _locCtrl.text.trim(),
      authorName:   _isAnonymous ? null : (_nameCtrl.text.trim().isEmpty ? null : _nameCtrl.text.trim()),
      isAnonymous:  _isAnonymous,
      photoPath:    _pickedPhoto?.path,
    );

    if (mounted) {
      setState(() {
        _submitting = false;
        _success    = result['success'] == true;
        _resultMsg  = result['message'] as String? ??
            (_success ? AppStrings.complaintSubmitted : AppStrings.loadingFailed);
      });
      if (_success) {
        widget.onSubmitted?.call();
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final dark = Theme.of(context).brightness == Brightness.dark;
    return Scaffold(
      appBar: AppBar(
        title: const Text(AppStrings.complaintSubmitBtn),
        backgroundColor: Theme.of(context).colorScheme.surface,
        elevation: 0,
      ),
      body: _success
          ? _SuccessView(onBack: () => Navigator.pop(context))
          : _buildForm(dark),
    );
  }

  Widget _buildForm(bool dark) {
    return SingleChildScrollView(
      padding: const EdgeInsets.fromLTRB(16, 16, 16, 32),
      child: Form(
        key: _formKey,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            // ── Category ──────────────────────────────────────────────
            Text(AppStrings.complaintCategory,
                style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 13)),
            const SizedBox(height: 6),
            DropdownButtonFormField<ComplaintCategory>(
              value: _selectedCategory,
              decoration: _inputDeco(),
              hint: Text(AppStrings.complaintCategory),
              items: widget.categories
                  .map((c) => DropdownMenuItem(value: c, child: Text(c.name)))
                  .toList(),
              onChanged: (v) => setState(() => _selectedCategory = v),
              validator: (v) => v == null ? 'Please select a category' : null,
            ),
            const SizedBox(height: 14),

            // ── Title ─────────────────────────────────────────────────
            Text(AppStrings.complaintTitle,
                style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 13)),
            const SizedBox(height: 6),
            TextFormField(
              controller: _titleCtrl,
              decoration: _inputDeco(hint: 'e.g. Broken road on MG Road'),
              maxLength:  255,
              validator: (v) =>
                  (v == null || v.trim().isEmpty) ? 'Title is required' : null,
            ),
            const SizedBox(height: 14),

            // ── Description ───────────────────────────────────────────
            Text(AppStrings.complaintDescription,
                style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 13)),
            const SizedBox(height: 6),
            TextFormField(
              controller: _descCtrl,
              decoration: _inputDeco(hint: 'Describe the issue in detail…'),
              maxLines:   5,
              maxLength:  3000,
              validator: (v) =>
                  (v == null || v.trim().isEmpty) ? 'Description is required' : null,
            ),
            const SizedBox(height: 14),

            // ── Location ──────────────────────────────────────────────
            Text('Location (optional)',
                style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 13)),
            const SizedBox(height: 6),
            TextFormField(
              controller: _locCtrl,
              decoration: _inputDeco(hint: 'Street, landmark or area name'),
              maxLength:  255,
            ),
            const SizedBox(height: 14),

            // ── Photo ─────────────────────────────────────────────────
            OutlinedButton.icon(
              onPressed: _pickPhoto,
              icon:  const Icon(Icons.photo_camera_outlined,
                  color: AppColors.primary),
              label: Text(
                _pickedPhoto == null
                    ? AppStrings.complaintAddPhoto
                    : 'Photo selected ✓',
                style: const TextStyle(color: AppColors.primary),
              ),
              style: OutlinedButton.styleFrom(
                side: const BorderSide(color: AppColors.primary),
                padding: const EdgeInsets.symmetric(vertical: 12),
              ),
            ),
            if (_pickedPhoto != null) ...[
              const SizedBox(height: 8),
              ClipRRect(
                borderRadius: BorderRadius.circular(6),
                child: Image.file(File(_pickedPhoto!.path),
                    height: 140, fit: BoxFit.cover),
              ),
            ],
            const SizedBox(height: 14),

            // ── Name ──────────────────────────────────────────────────
            if (!_isAnonymous) ...[
              Text(AppStrings.complaintNameHint,
                  style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 13)),
              const SizedBox(height: 6),
              TextFormField(
                controller: _nameCtrl,
                decoration: _inputDeco(hint: 'Your name (optional)'),
                maxLength:  100,
              ),
              const SizedBox(height: 6),
            ],

            // ── Anonymous toggle ──────────────────────────────────────
            SwitchListTile(
              contentPadding: EdgeInsets.zero,
              title: const Text(AppStrings.complaintAnonymous,
                  style: TextStyle(fontSize: 14)),
              value:    _isAnonymous,
              onChanged: (v) => setState(() => _isAnonymous = v),
              activeColor: AppColors.primary,
            ),
            const SizedBox(height: 20),

            // ── Error message ─────────────────────────────────────────
            if (_resultMsg != null && !_success)
              Padding(
                padding: const EdgeInsets.only(bottom: 10),
                child: Text(_resultMsg!,
                    style: const TextStyle(color: AppColors.primary)),
              ),

            // ── Submit ────────────────────────────────────────────────
            ElevatedButton(
              onPressed: _submitting ? null : _submit,
              style: ElevatedButton.styleFrom(
                backgroundColor: AppColors.primary,
                padding: const EdgeInsets.symmetric(vertical: 14),
                shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(8)),
              ),
              child: _submitting
                  ? const SizedBox(
                      height: 20, width: 20,
                      child: CircularProgressIndicator(
                          color: Colors.white, strokeWidth: 2))
                  : Text(AppStrings.complaintSubmitBtn,
                      style: const TextStyle(
                          color: Colors.white, fontSize: 16)),
            ),
          ],
        ),
      ),
    );
  }

  InputDecoration _inputDeco({String? hint}) => InputDecoration(
    hintText:      hint,
    border:        OutlineInputBorder(borderRadius: BorderRadius.circular(8)),
    enabledBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(8),
        borderSide: const BorderSide(color: Colors.grey, width: 0.8)),
    focusedBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(8),
        borderSide: const BorderSide(color: AppColors.primary)),
    contentPadding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
    counterText: '',
  );
}

class _SuccessView extends StatelessWidget {
  const _SuccessView({required this.onBack});
  final VoidCallback onBack;

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(32),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Icon(Icons.check_circle_outline_rounded,
                color: Colors.green, size: 80),
            const SizedBox(height: 16),
            const Text(
              AppStrings.complaintSubmitted,
              textAlign: TextAlign.center,
              style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold),
            ),
            const SizedBox(height: 8),
            const Text(
              'Your complaint is under review and will be published shortly.',
              textAlign: TextAlign.center,
              style: TextStyle(color: Colors.grey),
            ),
            const SizedBox(height: 24),
            ElevatedButton(
              onPressed: onBack,
              style: ElevatedButton.styleFrom(
                backgroundColor: AppColors.primary,
                padding: const EdgeInsets.symmetric(
                    horizontal: 32, vertical: 12)),
              child: const Text('Back to Complaints',
                  style: TextStyle(color: Colors.white)),
            ),
          ],
        ),
      ),
    );
  }
}
