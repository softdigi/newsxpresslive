import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';
import '../../data/models/listing_model.dart';
import '../../data/services/listing_service.dart';
import '../../core/constants/app_colors.dart';
import 'listing_detail_screen.dart';

/// Full listing detail — image gallery, contact actions, inquiry form.
class ListingDetailScreen extends StatefulWidget {
  const ListingDetailScreen({super.key, required this.listingId});
  final int listingId;

  @override
  State<ListingDetailScreen> createState() => _ListingDetailScreenState();
}

class _ListingDetailScreenState extends State<ListingDetailScreen> {
  final _svc = ListingService();
  ListingDetail? _listing;
  List<ListingSummary> _similar = [];
  bool _loading = true;
  int  _imgIndex = 0;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final res = await _svc.getDetail(id: widget.listingId);
    if (mounted) {
      setState(() {
        _listing = res?.listing;
        _similar = res?.similar ?? [];
        _loading = false;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    return Scaffold(
      backgroundColor: isDark ? AppColors.scaffoldDark : AppColors.scaffoldLight,
      appBar: AppBar(
        title: Text(_listing?.title ?? 'विज्ञापन विवरण'),
        backgroundColor: AppColors.primary,
        foregroundColor: Colors.white,
        actions: [
          if (_listing != null)
            IconButton(
              icon: Icon(
                _listing!.isSaved
                    ? Icons.bookmark_rounded
                    : Icons.bookmark_border_rounded,
              ),
              onPressed: _toggleSave,
            ),
        ],
      ),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : _listing == null
              ? const Center(child: Text('विज्ञापन नहीं मिला'))
              : _buildContent(isDark),
    );
  }

  Widget _buildContent(bool isDark) {
    final l = _listing!;
    return SingleChildScrollView(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          // Image gallery
          _buildImageGallery(l),
          Padding(
            padding: const EdgeInsets.all(16),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                // Title & price
                Text(l.title,
                    style: const TextStyle(
                        fontSize: 20, fontWeight: FontWeight.bold)),
                const SizedBox(height: 6),
                Row(
                  children: [
                    Text(l.priceDisplay,
                        style: TextStyle(
                          fontSize: 22,
                          fontWeight: FontWeight.bold,
                          color: AppColors.primary,
                        )),
                    if (l.priceNegotiable) ...[
                      const SizedBox(width: 8),
                      Container(
                        padding: const EdgeInsets.symmetric(
                            horizontal: 8, vertical: 3),
                        decoration: BoxDecoration(
                          color: Colors.green.shade50,
                          borderRadius: BorderRadius.circular(6),
                          border: Border.all(color: Colors.green),
                        ),
                        child: const Text('Negotiable',
                            style: TextStyle(
                                fontSize: 11, color: Colors.green)),
                      ),
                    ],
                  ],
                ),
                const SizedBox(height: 8),
                // Location
                if (l.city != null)
                  Row(
                    children: [
                      const Icon(Icons.location_on_outlined,
                          size: 16, color: Colors.grey),
                      const SizedBox(width: 4),
                      Text(l.city!,
                          style: const TextStyle(
                              fontSize: 13, color: Colors.grey)),
                    ],
                  ),
                const SizedBox(height: 6),
                Text(_formatDate(l.createdAt),
                    style: const TextStyle(fontSize: 11, color: Colors.grey)),

                const Divider(height: 24),

                // Description
                const Text('विवरण',
                    style: TextStyle(
                        fontSize: 16, fontWeight: FontWeight.bold)),
                const SizedBox(height: 8),
                Text(l.description, style: const TextStyle(height: 1.5)),

                const Divider(height: 24),

                // Seller info
                _buildSellerInfo(l),

                const Divider(height: 24),

                // Stats
                Row(
                  children: [
                    const Icon(Icons.remove_red_eye_outlined,
                        size: 16, color: Colors.grey),
                    const SizedBox(width: 4),
                    Text('${l.viewsCount} views',
                        style: const TextStyle(
                            fontSize: 12, color: Colors.grey)),
                    const SizedBox(width: 16),
                    const Icon(Icons.bookmark_border_rounded,
                        size: 16, color: Colors.grey),
                    const SizedBox(width: 4),
                    Text('${l.savesCount} saves',
                        style: const TextStyle(
                            fontSize: 12, color: Colors.grey)),
                  ],
                ),

                // Contact actions
                const SizedBox(height: 16),
                _buildContactRow(l),

                const SizedBox(height: 12),
                _buildInquiryButton(l),

                // Similar listings
                if (_similar.isNotEmpty) ...[
                  const Divider(height: 32),
                  const Text('इसी तरह के विज्ञापन',
                      style: TextStyle(
                          fontSize: 16, fontWeight: FontWeight.bold)),
                  const SizedBox(height: 8),
                  SizedBox(
                    height: 180,
                    child: ListView.builder(
                      scrollDirection: Axis.horizontal,
                      itemCount: _similar.length,
                      itemBuilder: (ctx, i) =>
                          _SimilarCard(listing: _similar[i]),
                    ),
                  ),
                ],
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildImageGallery(ListingDetail l) {
    if (l.images.isEmpty) {
      return Container(
        height: 220,
        color: Colors.grey.shade200,
        child: const Center(
            child: Icon(Icons.image_outlined, size: 60, color: Colors.grey)),
      );
    }
    return Stack(
      alignment: Alignment.bottomCenter,
      children: [
        SizedBox(
          height: 260,
          child: PageView.builder(
            itemCount: l.images.length,
            onPageChanged: (i) => setState(() => _imgIndex = i),
            itemBuilder: (ctx, i) => Image.network(
              l.images[i],
              fit: BoxFit.cover,
              errorBuilder: (_, __, ___) => Container(
                color: Colors.grey.shade200,
                child: const Icon(Icons.broken_image_outlined, size: 60),
              ),
            ),
          ),
        ),
        if (l.images.length > 1)
          Padding(
            padding: const EdgeInsets.only(bottom: 10),
            child: Row(
              mainAxisAlignment: MainAxisAlignment.center,
              children: List.generate(
                l.images.length,
                (i) => Container(
                  width: i == _imgIndex ? 18 : 8,
                  height: 8,
                  margin: const EdgeInsets.symmetric(horizontal: 2),
                  decoration: BoxDecoration(
                    color: i == _imgIndex
                        ? AppColors.primary
                        : Colors.white.withAlpha(180),
                    borderRadius: BorderRadius.circular(4),
                  ),
                ),
              ),
            ),
          ),
      ],
    );
  }

  Widget _buildSellerInfo(ListingDetail l) {
    if (l.contactName == null) return const SizedBox.shrink();
    return Row(
      children: [
        CircleAvatar(
          backgroundColor: AppColors.primary.withAlpha(30),
          child: const Icon(Icons.person, color: AppColors.primary),
        ),
        const SizedBox(width: 12),
        Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(l.contactName!,
                style: const TextStyle(fontWeight: FontWeight.w600)),
            const Text('विक्रेता',
                style: TextStyle(fontSize: 11, color: Colors.grey)),
          ],
        ),
      ],
    );
  }

  Widget _buildContactRow(ListingDetail l) {
    final hasPhone = l.contactPhone != null && l.showPhone;
    final hasWa    = l.contactWhatsapp != null;
    if (!hasPhone && !hasWa) return const SizedBox.shrink();

    return Row(
      children: [
        if (hasPhone) ...[
          Expanded(
            child: OutlinedButton.icon(
              onPressed: () =>
                  launchUrl(Uri.parse('tel:${l.contactPhone}')),
              icon: const Icon(Icons.phone_outlined, size: 18),
              label: const Text('कॉल करें'),
              style: OutlinedButton.styleFrom(
                  foregroundColor: AppColors.primary,
                  side: const BorderSide(color: AppColors.primary)),
            ),
          ),
          const SizedBox(width: 8),
        ],
        if (hasWa)
          Expanded(
            child: ElevatedButton.icon(
              onPressed: () => launchUrl(
                  Uri.parse('https://wa.me/${l.contactWhatsapp}')),
              icon: const Icon(Icons.chat_bubble_outline_rounded, size: 18),
              label: const Text('WhatsApp'),
              style: ElevatedButton.styleFrom(
                  backgroundColor: Colors.green,
                  foregroundColor: Colors.white),
            ),
          ),
      ],
    );
  }

  Widget _buildInquiryButton(ListingDetail l) => SizedBox(
        width: double.infinity,
        child: FilledButton.icon(
          onPressed: () => _showInquiryDialog(l),
          icon: const Icon(Icons.mail_outline_rounded),
          label: const Text('पूछताछ करें'),
          style: FilledButton.styleFrom(
              backgroundColor: AppColors.primary),
        ),
      );

  void _showInquiryDialog(ListingDetail l) {
    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      builder: (ctx) => _InquirySheet(
        listingId: l.id,
        svc: _svc,
      ),
    );
  }

  Future<void> _toggleSave() async {
    final res = await _svc.toggleSave(
      firebaseUid: 'guest',
      listingId: widget.listingId,
    );
    if (res != null && mounted) {
      setState(() {
        _listing = ListingDetail(
          id: _listing!.id,
          title: _listing!.title,
          thumb: _listing!.thumb,
          images: _listing!.images,
          price: _listing!.price,
          priceNegotiable: _listing!.priceNegotiable,
          priceType: _listing!.priceType,
          listingType: _listing!.listingType,
          city: _listing!.city,
          stateId: _listing!.stateId,
          districtId: _listing!.districtId,
          category: _listing!.category,
          categoryHi: _listing!.categoryHi,
          categoryIcon: _listing!.categoryIcon,
          isFeatured: _listing!.isFeatured,
          viewsCount: _listing!.viewsCount,
          savesCount: res.savesCount,
          createdAt: _listing!.createdAt,
          userId: _listing!.userId,
          categoryId: _listing!.categoryId,
          description: _listing!.description,
          pincode: _listing!.pincode,
          latitude: _listing!.latitude,
          longitude: _listing!.longitude,
          contactName: _listing!.contactName,
          contactPhone: _listing!.contactPhone,
          showPhone: _listing!.showPhone,
          contactWhatsapp: _listing!.contactWhatsapp,
          status: _listing!.status,
          expiresAt: _listing!.expiresAt,
          isSaved: res.saved,
        );
      });
    }
  }

  String _formatDate(String iso) {
    final dt = DateTime.tryParse(iso);
    if (dt == null) return iso;
    return '${dt.day}/${dt.month}/${dt.year}';
  }
}

// ── Inquiry Sheet ─────────────────────────────────────────────────────────────

class _InquirySheet extends StatefulWidget {
  const _InquirySheet({required this.listingId, required this.svc});
  final int listingId;
  final ListingService svc;

  @override
  State<_InquirySheet> createState() => _InquirySheetState();
}

class _InquirySheetState extends State<_InquirySheet> {
  final _msgCtrl   = TextEditingController();
  final _phoneCtrl = TextEditingController();
  bool _sending = false;

  @override
  void dispose() {
    _msgCtrl.dispose();
    _phoneCtrl.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: EdgeInsets.only(
        bottom: MediaQuery.of(context).viewInsets.bottom,
        left: 16, right: 16, top: 16,
      ),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Text('पूछताछ भेजें',
              style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold)),
          const SizedBox(height: 12),
          TextField(
            controller: _msgCtrl,
            maxLines: 3,
            decoration: const InputDecoration(
              hintText: 'अपना संदेश लिखें...',
              border: OutlineInputBorder(),
            ),
          ),
          const SizedBox(height: 10),
          TextField(
            controller: _phoneCtrl,
            keyboardType: TextInputType.phone,
            decoration: const InputDecoration(
              hintText: 'फोन नंबर (वैकल्पिक)',
              border: OutlineInputBorder(),
              prefixIcon: Icon(Icons.phone_outlined),
            ),
          ),
          const SizedBox(height: 12),
          SizedBox(
            width: double.infinity,
            child: FilledButton(
              onPressed: _sending ? null : _send,
              style: FilledButton.styleFrom(
                  backgroundColor: AppColors.primary),
              child: _sending
                  ? const SizedBox(
                      width: 18,
                      height: 18,
                      child: CircularProgressIndicator(
                          strokeWidth: 2, color: Colors.white))
                  : const Text('भेजें'),
            ),
          ),
          const SizedBox(height: 16),
        ],
      ),
    );
  }

  Future<void> _send() async {
    final msg = _msgCtrl.text.trim();
    if (msg.isEmpty) return;
    setState(() => _sending = true);
    final ok = await widget.svc.sendInquiry(
      firebaseUid: 'guest',
      listingId:   widget.listingId,
      message:     msg,
      contactPhone: _phoneCtrl.text.trim().isEmpty
          ? null
          : _phoneCtrl.text.trim(),
    );
    if (mounted) {
      Navigator.pop(context);
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(ok
              ? 'पूछताछ भेज दी गई ✓'
              : 'कुछ गड़बड़ हुई, फिर से कोशिश करें'),
        ),
      );
    }
  }
}

// ── Similar Card ──────────────────────────────────────────────────────────────

class _SimilarCard extends StatelessWidget {
  const _SimilarCard({required this.listing});
  final ListingSummary listing;

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTap: () => Navigator.pushReplacement(
        context,
        MaterialPageRoute(
          builder: (_) => ListingDetailScreen(listingId: listing.id),
        ),
      ),
      child: Container(
        width: 140,
        margin: const EdgeInsets.only(right: 10),
        decoration: BoxDecoration(
          color: Theme.of(context).brightness == Brightness.dark
              ? AppColors.cardDark
              : AppColors.cardLight,
          borderRadius: BorderRadius.circular(10),
          boxShadow: [BoxShadow(color: Colors.black12, blurRadius: 4)],
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            ClipRRect(
              borderRadius:
                  const BorderRadius.vertical(top: Radius.circular(10)),
              child: listing.thumb != null
                  ? Image.network(listing.thumb!,
                      height: 100,
                      width: double.infinity,
                      fit: BoxFit.cover,
                      errorBuilder: (_, __, ___) => Container(
                            height: 100,
                            color: Colors.grey.shade200,
                          ))
                  : Container(
                      height: 100,
                      color: Colors.grey.shade200,
                      child: const Icon(Icons.image_outlined)),
            ),
            Padding(
              padding: const EdgeInsets.all(6),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(listing.title,
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(fontSize: 12)),
                  const SizedBox(height: 2),
                  Text(listing.priceDisplay,
                      style: TextStyle(
                          fontSize: 13,
                          fontWeight: FontWeight.bold,
                          color: AppColors.primary)),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}
