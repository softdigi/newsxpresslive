import 'dart:io';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';
import 'package:image_picker/image_picker.dart';
import '../../../providers/auth_provider.dart';
import '../../../data/services/api_service.dart';
import '../../../data/services/analytics_service.dart';
import '../../../data/services/moderation_service.dart';
import '../../../data/models/category.dart';
import '../../../data/models/location_model.dart';
import '../../../data/services/news_service.dart';
import '../../../core/constants/app_colors.dart';
import '../../../core/constants/app_strings.dart';
import '../../../core/constants/api_endpoints.dart';

/// Reporter mode — allows authenticated users to submit a news story.
///
/// Fields: title, description, category, language, optional image.
/// Calls [ApiEndpoints.submitNews] then optionally uploads an image.
class SubmitNewsScreen extends StatefulWidget {
  const SubmitNewsScreen({super.key});

  @override
  State<SubmitNewsScreen> createState() => _SubmitNewsScreenState();
}

class _SubmitNewsScreenState extends State<SubmitNewsScreen> {
  final _formKey     = GlobalKey<FormState>();
  final _titleCtrl   = TextEditingController();
  final _descCtrl    = TextEditingController();

  XFile?           _pickedImage;
  Category?        _selectedCategory;
  LanguageModel?   _selectedLanguage;

  List<Category>      _categories = [];
  List<LanguageModel> _languages  = [];

  bool    _loading    = false;
  bool    _submitting = false;
  String? _resultMsg;
  bool    _success    = false;

  @override
  void initState() {
    super.initState();
    _loadData();
  }

  @override
  void dispose() {
    _titleCtrl.dispose();
    _descCtrl.dispose();
    super.dispose();
  }

  Future<void> _loadData() async {
    setState(() => _loading = true);
    try {
      final api     = NewsService(api: ApiService());
      _categories   = await api.getCategories();
      // Languages are fetched via OnboardingService using same endpoint
      final langApi = ApiService();
      final langData = await langApi.get(ApiEndpoints.languages);
      langApi.dispose();
      api.dispose();
      if (langData is Map && langData['data'] is List) {
        _languages = (langData['data'] as List)
            .map((e) => LanguageModel.fromJson(e as Map<String, dynamic>))
            .toList();
      }
    } catch (_) {}
    if (mounted) setState(() => _loading = false);
  }

  Future<void> _pickImage() async {
    HapticFeedback.lightImpact();
    final picker = ImagePicker();
    final file   = await picker.pickImage(
        source: ImageSource.gallery, imageQuality: 80);
    if (file != null) setState(() => _pickedImage = file);
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;
    if (_selectedCategory == null) {
      _showError('Please select a category.');
      return;
    }
    if (_selectedLanguage == null) {
      _showError('Please select a language.');
      return;
    }

    final auth = context.read<AuthProvider>();
    if (auth.user == null || auth.isGuest) {
      _showError('You must be signed in to submit news.');
      return;
    }

    // ── Moderation check ─────────────────────────────────────────────────
    final modResult = ModerationService.instance.analyseSubmission(
      title:       _titleCtrl.text.trim(),
      description: _descCtrl.text.trim(),
    );
    if (modResult.isBlock) {
      _showError(modResult.reason);
      return;
    }

    HapticFeedback.mediumImpact();
    setState(() { _submitting = true; _resultMsg = null; });

    try {
      final token = await auth.getIdToken();
      final api   = ApiService(
        idTokenProvider: () async => token,
      );

      // Submit news metadata (include optional moderation_flag for admin)
      final body = <String, dynamic>{
        'firebase_uid': auth.user!.firebaseUid,
        'title':        _titleCtrl.text.trim(),
        'description':  _descCtrl.text.trim(),
        'category_id':  _selectedCategory!.id,
        'language_id':  _selectedLanguage!.id,
        if (modResult.backendFlag != null)
          'moderation_flag': modResult.backendFlag,
      };
      final res = await api.postJson(ApiEndpoints.submitNews, body: body);

      final newsId = res?['news_id'] as int?;

      // Upload image if selected
      if (newsId != null && _pickedImage != null) {
        await api.postMultipart(
          ApiEndpoints.uploadNewsImage,
          fields: {'news_id': newsId.toString()},
          filePaths: {'image': _pickedImage!.path},
        );
      }

      api.dispose();

      await AnalyticsService.instance.logNewsSubmit();

      setState(() {
        _success    = true;
        _resultMsg  = modResult.isWarn
            ? modResult.reason
            : AppStrings.newsPending;
        _submitting = false;
      });

      // Reset form
      _titleCtrl.clear();
      _descCtrl.clear();
      setState(() {
        _pickedImage      = null;
        _selectedCategory = null;
        _selectedLanguage = null;
      });
    } on ApiException catch (e) {
      setState(() { _resultMsg = e.message; _submitting = false; _success = false; });
    } catch (_) {
      setState(() {
        _resultMsg  = 'Submission failed. Please try again.';
        _submitting = false;
        _success    = false;
      });
    }
  }

  void _showError(String msg) {
    ScaffoldMessenger.of(context)
        .showSnackBar(SnackBar(content: Text(msg)));
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text(AppStrings.submitNews)),
      body: _loading
          ? const Center(
              child: CircularProgressIndicator(color: AppColors.primary))
          : SingleChildScrollView(
              padding: const EdgeInsets.all(20),
              child: Form(
                key: _formKey,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    // Image picker
                    _ImagePickerWidget(
                      image:    _pickedImage,
                      onTap:    _pickImage,
                    ),
                    const SizedBox(height: 20),

                    // Title
                    TextFormField(
                      controller:  _titleCtrl,
                      decoration:  const InputDecoration(
                          labelText: AppStrings.newsTitle),
                      maxLength: 200,
                      validator: (v) =>
                          (v == null || v.trim().isEmpty)
                              ? 'Title is required'
                              : null,
                    ),
                    const SizedBox(height: 16),

                    // Description
                    TextFormField(
                      controller: _descCtrl,
                      decoration: const InputDecoration(
                          labelText: AppStrings.newsDescription),
                      maxLines: 6,
                      maxLength: 2000,
                      validator: (v) =>
                          (v == null || v.trim().isEmpty)
                              ? 'Description is required'
                              : null,
                    ),
                    const SizedBox(height: 16),

                    // Category
                    DropdownButtonFormField<Category>(
                      value:       _selectedCategory,
                      hint: const Text(AppStrings.selectCategory),
                      decoration:  const InputDecoration(
                          labelText: AppStrings.selectCategory),
                      isExpanded:  true,
                      items: _categories
                          .map((c) => DropdownMenuItem(
                                value: c,
                                child: Text(c.name),
                              ))
                          .toList(),
                      onChanged: (c) =>
                          setState(() => _selectedCategory = c),
                    ),
                    const SizedBox(height: 16),

                    // Language
                    DropdownButtonFormField<LanguageModel>(
                      value:      _selectedLanguage,
                      hint: const Text(AppStrings.selectLanguage),
                      decoration: const InputDecoration(
                          labelText: AppStrings.selectLanguage),
                      isExpanded: true,
                      items: _languages
                          .map((l) => DropdownMenuItem(
                                value: l,
                                child: Text(l.name),
                              ))
                          .toList(),
                      onChanged: (l) =>
                          setState(() => _selectedLanguage = l),
                    ),
                    const SizedBox(height: 24),

                    // Result message
                    if (_resultMsg != null)
                      Container(
                        padding: const EdgeInsets.all(12),
                        decoration: BoxDecoration(
                          color: _success
                              ? Colors.green.shade50
                              : Colors.red.shade50,
                          borderRadius: BorderRadius.circular(8),
                        ),
                        child: Row(
                          children: [
                            Icon(
                              _success
                                  ? Icons.check_circle_outline_rounded
                                  : Icons.error_outline_rounded,
                              color: _success ? Colors.green : Colors.red,
                              size: 18,
                            ),
                            const SizedBox(width: 8),
                            Expanded(
                              child: Text(_resultMsg!,
                                  style: TextStyle(
                                    color:    _success
                                        ? Colors.green.shade700
                                        : Colors.red.shade700,
                                    fontSize: 13,
                                  )),
                            ),
                          ],
                        ),
                      ),
                    if (_resultMsg != null) const SizedBox(height: 16),

                    // Submit button
                    SizedBox(
                      width: double.infinity,
                      child: ElevatedButton(
                        onPressed: _submitting ? null : _submit,
                        child: _submitting
                            ? const SizedBox(
                                height: 18,
                                width:  18,
                                child: CircularProgressIndicator(
                                    color: Colors.white, strokeWidth: 2))
                            : const Text(AppStrings.submitForReview),
                      ),
                    ),
                    const SizedBox(height: 40),
                  ],
                ),
              ),
            ),
    );
  }
}

// ── Image picker widget ────────────────────────────────────────────────────

class _ImagePickerWidget extends StatelessWidget {
  const _ImagePickerWidget({required this.image, required this.onTap});

  final XFile?   image;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTap: onTap,
      child: Container(
        height:  180,
        width:   double.infinity,
        decoration: BoxDecoration(
          color:        Colors.grey.shade100,
          borderRadius: BorderRadius.circular(12),
          border:       Border.all(color: Colors.grey.shade300),
        ),
        child: image == null
            ? Column(
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  Icon(Icons.add_photo_alternate_outlined,
                      size: 40, color: Colors.grey.shade400),
                  const SizedBox(height: 8),
                  Text(AppStrings.addPhoto,
                      style: TextStyle(color: Colors.grey.shade500)),
                ],
              )
            : ClipRRect(
                borderRadius: BorderRadius.circular(12),
                child: Stack(
                  fit: StackFit.expand,
                  children: [
                    Image.file(File(image!.path), fit: BoxFit.cover),
                    Positioned(
                      right: 8,
                      top:   8,
                      child: Container(
                        padding: const EdgeInsets.symmetric(
                            horizontal: 8, vertical: 4),
                        decoration: BoxDecoration(
                          color:        Colors.black54,
                          borderRadius: BorderRadius.circular(4),
                        ),
                        child: const Text(AppStrings.changePhoto,
                            style: TextStyle(
                                color: Colors.white, fontSize: 12)),
                      ),
                    ),
                  ],
                ),
              ),
      ),
    );
  }
}
