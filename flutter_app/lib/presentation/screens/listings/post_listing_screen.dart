import 'dart:io';
import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';
import '../../data/services/listing_service.dart';
import '../../core/constants/app_colors.dart';

/// Post a new listing — category, photos, details, price, contact.
class PostListingScreen extends StatefulWidget {
  const PostListingScreen({super.key});

  @override
  State<PostListingScreen> createState() => _PostListingScreenState();
}

class _PostListingScreenState extends State<PostListingScreen> {
  final _svc        = ListingService();
  final _formKey    = GlobalKey<FormState>();
  final _titleCtrl  = TextEditingController();
  final _descCtrl   = TextEditingController();
  final _priceCtrl  = TextEditingController();
  final _cityCtrl   = TextEditingController();
  final _pinCtrl    = TextEditingController();
  final _nameCtrl   = TextEditingController();
  final _phoneCtrl  = TextEditingController();
  final _waCtrl     = TextEditingController();

  int    _categoryId   = 1;
  String _listingType  = 'sell';
  String _priceType    = 'fixed';
  bool   _priceNeg     = false;
  bool   _showPhone    = true;
  bool   _submitting   = false;
  List<File> _images   = [];

  static const _categories = [
    (1, 'Property / संपत्ति'),
    (2, 'Vehicles / वाहन'),
    (3, 'Electronics / इलेक्ट्रॉनिक्स'),
    (4, 'Furniture / फर्नीचर'),
    (5, 'Services / सेवाएं'),
    (6, 'Agriculture / कृषि'),
    (7, 'Education / शिक्षा'),
    (8, 'Wanted / चाहिए'),
  ];

  static const _listingTypes = [
    ('sell',    'बेचना है'),
    ('service', 'सेवा'),
    ('rent',    'किराए पर'),
    ('wanted',  'चाहिए'),
  ];

  static const _priceTypes = [
    ('fixed',     'Fixed'),
    ('per_day',   'Per Day'),
    ('per_month', 'Per Month'),
    ('per_hour',  'Per Hour'),
    ('free',      'Free'),
  ];

  @override
  void dispose() {
    for (final c in [
      _titleCtrl, _descCtrl, _priceCtrl, _cityCtrl, _pinCtrl,
      _nameCtrl, _phoneCtrl, _waCtrl,
    ]) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _pickImages() async {
    if (_images.length >= 10) return;
    final picker = ImagePicker();
    final picked = await picker.pickMultiImage(imageQuality: 75);
    if (picked.isNotEmpty) {
      final remaining = 10 - _images.length;
      setState(() {
        _images.addAll(
            picked.take(remaining).map((x) => File(x.path)));
      });
    }
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;
    setState(() => _submitting = true);

    final res = await _svc.postListing(
      firebaseUid:     'guest',
      categoryId:      _categoryId,
      title:           _titleCtrl.text.trim(),
      description:     _descCtrl.text.trim(),
      listingType:     _listingType,
      price:           _priceCtrl.text.trim().isEmpty
          ? null
          : double.tryParse(_priceCtrl.text.trim()),
      priceNegotiable: _priceNeg,
      priceType:       _priceType,
      city:            _cityCtrl.text.trim().isEmpty ? null : _cityCtrl.text.trim(),
      pincode:         _pinCtrl.text.trim().isEmpty  ? null : _pinCtrl.text.trim(),
      contactName:     _nameCtrl.text.trim().isEmpty  ? null : _nameCtrl.text.trim(),
      contactPhone:    _phoneCtrl.text.trim().isEmpty ? null : _phoneCtrl.text.trim(),
      showPhone:       _showPhone,
      contactWhatsapp: _waCtrl.text.trim().isEmpty    ? null : _waCtrl.text.trim(),
      images: _images,
    );

    setState(() => _submitting = false);

    if (!mounted) return;

    if (res.error != null) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('Error: ${res.error}')),
      );
      return;
    }

    Navigator.pop(context);
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(res.status == 'pending'
            ? 'विज्ञापन समीक्षा के लिए भेज दिया गया ✓'
            : 'विज्ञापन पोस्ट हो गया ✓'),
        backgroundColor: Colors.green,
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    return Scaffold(
      appBar: AppBar(
        title: const Text('विज्ञापन पोस्ट करें'),
        backgroundColor: AppColors.primary,
        foregroundColor: Colors.white,
      ),
      body: Form(
        key: _formKey,
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              _sectionTitle('श्रेणी'),
              DropdownButtonFormField<int>(
                value: _categoryId,
                items: _categories
                    .map((c) => DropdownMenuItem(
                          value: c.$1,
                          child: Text(c.$2),
                        ))
                    .toList(),
                onChanged: (v) => setState(() => _categoryId = v!),
                decoration: _inputDecor('श्रेणी चुनें'),
              ),
              const SizedBox(height: 12),

              _sectionTitle('विज्ञापन प्रकार'),
              Wrap(
                spacing: 8,
                children: _listingTypes.map((t) {
                  final sel = _listingType == t.$1;
                  return ChoiceChip(
                    label: Text(t.$2),
                    selected: sel,
                    selectedColor: AppColors.primary,
                    labelStyle: TextStyle(
                        color: sel ? Colors.white : null),
                    onSelected: (_) =>
                        setState(() => _listingType = t.$1),
                  );
                }).toList(),
              ),
              const SizedBox(height: 12),

              _sectionTitle('फोटो (अधिकतम 10)'),
              _buildImagePicker(isDark),
              const SizedBox(height: 12),

              _sectionTitle('विवरण'),
              TextFormField(
                controller: _titleCtrl,
                decoration: _inputDecor('शीर्षक *'),
                maxLength: 200,
                validator: (v) =>
                    v == null || v.trim().isEmpty ? 'शीर्षक आवश्यक है' : null,
              ),
              const SizedBox(height: 8),
              TextFormField(
                controller: _descCtrl,
                decoration: _inputDecor('विवरण *'),
                maxLines: 4,
                validator: (v) =>
                    v == null || v.trim().isEmpty ? 'विवरण आवश्यक है' : null,
              ),
              const SizedBox(height: 12),

              _sectionTitle('मूल्य'),
              Row(
                children: [
                  Expanded(
                    flex: 2,
                    child: TextFormField(
                      controller: _priceCtrl,
                      keyboardType: TextInputType.number,
                      decoration: _inputDecor('₹ मूल्य'),
                    ),
                  ),
                  const SizedBox(width: 8),
                  Expanded(
                    child: DropdownButtonFormField<String>(
                      value: _priceType,
                      items: _priceTypes
                          .map((p) => DropdownMenuItem(
                                value: p.$1,
                                child: Text(p.$2,
                                    style: const TextStyle(fontSize: 13)),
                              ))
                          .toList(),
                      onChanged: (v) => setState(() => _priceType = v!),
                      decoration: _inputDecor('प्रकार'),
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 6),
              CheckboxListTile(
                value: _priceNeg,
                onChanged: (v) => setState(() => _priceNeg = v!),
                title: const Text('कीमत पर बात हो सकती है (Negotiable)',
                    style: TextStyle(fontSize: 14)),
                contentPadding: EdgeInsets.zero,
                controlAffinity: ListTileControlAffinity.leading,
              ),
              const SizedBox(height: 12),

              _sectionTitle('स्थान'),
              TextFormField(
                controller: _cityCtrl,
                decoration: _inputDecor('शहर'),
              ),
              const SizedBox(height: 8),
              TextFormField(
                controller: _pinCtrl,
                keyboardType: TextInputType.number,
                decoration: _inputDecor('पिनकोड'),
              ),
              const SizedBox(height: 12),

              _sectionTitle('संपर्क'),
              TextFormField(
                controller: _nameCtrl,
                decoration: _inputDecor('नाम'),
              ),
              const SizedBox(height: 8),
              TextFormField(
                controller: _phoneCtrl,
                keyboardType: TextInputType.phone,
                decoration: _inputDecor('फोन नंबर'),
              ),
              const SizedBox(height: 4),
              CheckboxListTile(
                value: _showPhone,
                onChanged: (v) => setState(() => _showPhone = v!),
                title: const Text('फोन नंबर दिखाएं',
                    style: TextStyle(fontSize: 14)),
                contentPadding: EdgeInsets.zero,
                controlAffinity: ListTileControlAffinity.leading,
              ),
              TextFormField(
                controller: _waCtrl,
                keyboardType: TextInputType.phone,
                decoration: _inputDecor('WhatsApp नंबर'),
              ),
              const SizedBox(height: 24),

              SizedBox(
                width: double.infinity,
                child: FilledButton(
                  onPressed: _submitting ? null : _submit,
                  style: FilledButton.styleFrom(
                    backgroundColor: AppColors.primary,
                    padding: const EdgeInsets.symmetric(vertical: 14),
                  ),
                  child: _submitting
                      ? const SizedBox(
                          width: 20,
                          height: 20,
                          child: CircularProgressIndicator(
                              strokeWidth: 2, color: Colors.white))
                      : const Text('विज्ञापन पोस्ट करें',
                          style: TextStyle(fontSize: 16)),
                ),
              ),
              const SizedBox(height: 24),
            ],
          ),
        ),
      ),
    );
  }

  Widget _buildImagePicker(bool isDark) {
    return Wrap(
      spacing: 8,
      runSpacing: 8,
      children: [
        // Existing images
        ..._images.asMap().entries.map((e) => Stack(
              children: [
                ClipRRect(
                  borderRadius: BorderRadius.circular(8),
                  child: Image.file(e.value,
                      width: 80, height: 80, fit: BoxFit.cover),
                ),
                Positioned(
                  top: 2,
                  right: 2,
                  child: GestureDetector(
                    onTap: () =>
                        setState(() => _images.removeAt(e.key)),
                    child: Container(
                      decoration: const BoxDecoration(
                          shape: BoxShape.circle,
                          color: Colors.black54),
                      child: const Icon(Icons.close,
                          size: 16, color: Colors.white),
                    ),
                  ),
                ),
              ],
            )),
        // Add button
        if (_images.length < 10)
          GestureDetector(
            onTap: _pickImages,
            child: Container(
              width: 80,
              height: 80,
              decoration: BoxDecoration(
                color: isDark ? AppColors.cardDark : Colors.grey.shade100,
                borderRadius: BorderRadius.circular(8),
                border: Border.all(
                    color: AppColors.primary.withAlpha(100)),
              ),
              child: Column(
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  Icon(Icons.add_a_photo_outlined,
                      color: AppColors.primary, size: 28),
                  Text(
                    '${_images.length}/10',
                    style: TextStyle(
                        fontSize: 10, color: AppColors.primary),
                  ),
                ],
              ),
            ),
          ),
      ],
    );
  }

  Widget _sectionTitle(String t) => Padding(
        padding: const EdgeInsets.only(bottom: 8),
        child: Text(t,
            style: const TextStyle(
                fontWeight: FontWeight.bold, fontSize: 15)),
      );

  InputDecoration _inputDecor(String hint) => InputDecoration(
        hintText: hint,
        border: const OutlineInputBorder(),
        contentPadding:
            const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
      );
}
