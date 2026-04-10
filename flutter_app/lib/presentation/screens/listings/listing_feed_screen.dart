import 'package:flutter/material.dart';
import '../../data/models/listing_model.dart';
import '../../data/services/listing_service.dart';
import '../../core/constants/app_colors.dart';
import 'listing_detail_screen.dart';
import 'post_listing_screen.dart';
import 'my_listings_screen.dart';

/// Listing Feed — category grid + filtered listing cards.
class ListingFeedScreen extends StatefulWidget {
  const ListingFeedScreen({super.key});

  @override
  State<ListingFeedScreen> createState() => _ListingFeedScreenState();
}

class _ListingFeedScreenState extends State<ListingFeedScreen> {
  final _svc = ListingService();
  final _scrollCtrl = ScrollController();
  final _searchCtrl = TextEditingController();

  int?   _selectedCategoryId;
  String? _selectedType;
  String _search = '';

  List<ListingSummary> _listings   = [];
  bool   _loading    = true;
  bool   _loadingMore = false;
  bool   _hasMore    = true;
  int    _cursor     = 0;

  static const _categories = [
    (null, 'सभी', Icons.apps_rounded),
    (1,  'संपत्ति',         Icons.home_rounded),
    (2,  'वाहन',            Icons.directions_car_rounded),
    (3,  'इलेक्ट्रॉनिक्स', Icons.devices_rounded),
    (4,  'फर्नीचर',         Icons.chair_rounded),
    (5,  'सेवाएं',          Icons.handyman_rounded),
    (6,  'कृषि',            Icons.agriculture_rounded),
    (7,  'शिक्षा',          Icons.school_rounded),
    (8,  'चाहिए',           Icons.search_rounded),
  ];

  @override
  void initState() {
    super.initState();
    _scrollCtrl.addListener(_onScroll);
    _fetchListings(reset: true);
  }

  @override
  void dispose() {
    _scrollCtrl.dispose();
    _searchCtrl.dispose();
    super.dispose();
  }

  void _onScroll() {
    if (_scrollCtrl.position.pixels >=
            _scrollCtrl.position.maxScrollExtent - 200 &&
        !_loadingMore &&
        _hasMore) {
      _fetchListings();
    }
  }

  Future<void> _fetchListings({bool reset = false}) async {
    if (reset) {
      setState(() {
        _listings = [];
        _cursor   = 0;
        _hasMore  = true;
        _loading  = true;
      });
    } else {
      setState(() => _loadingMore = true);
    }

    final res = await _svc.getFeed(
      categoryId:   _selectedCategoryId,
      listingType:  _selectedType,
      search:       _search.isEmpty ? null : _search,
      cursor:       _cursor,
      featuredFirst: true,
    );

    if (mounted) {
      setState(() {
        if (reset) _listings = res?.listings ?? [];
        else       _listings.addAll(res?.listings ?? []);
        _hasMore   = res?.hasMore ?? false;
        _cursor    = res?.nextCursor ?? 0;
        _loading   = false;
        _loadingMore = false;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    return Scaffold(
      backgroundColor: isDark ? AppColors.scaffoldDark : AppColors.scaffoldLight,
      body: SafeArea(
        child: Column(
          children: [
            _buildHeader(isDark),
            _buildSearchBar(isDark),
            _buildCategoryRow(isDark),
            Expanded(child: _buildBody(isDark)),
          ],
        ),
      ),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: () => Navigator.push(
          context,
          MaterialPageRoute(builder: (_) => const PostListingScreen()),
        ).then((_) => _fetchListings(reset: true)),
        backgroundColor: AppColors.primary,
        icon: const Icon(Icons.add, color: Colors.white),
        label: const Text('बेचें / दें', style: TextStyle(color: Colors.white)),
      ),
    );
  }

  Widget _buildHeader(bool isDark) => Container(
        padding: const EdgeInsets.fromLTRB(16, 12, 8, 8),
        color: isDark ? AppColors.cardDark : AppColors.cardLight,
        child: Row(
          children: [
            const Text('🛒', style: TextStyle(fontSize: 22)),
            const SizedBox(width: 8),
            const Expanded(
              child: Text('बाज़ार',
                  style: TextStyle(fontSize: 20, fontWeight: FontWeight.bold)),
            ),
            IconButton(
              icon: const Icon(Icons.list_alt_rounded),
              color: AppColors.primary,
              tooltip: 'मेरे विज्ञापन',
              onPressed: () => Navigator.push(
                context,
                MaterialPageRoute(builder: (_) => const MyListingsScreen()),
              ),
            ),
          ],
        ),
      );

  Widget _buildSearchBar(bool isDark) => Padding(
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
        child: TextField(
          controller: _searchCtrl,
          decoration: InputDecoration(
            hintText: 'खोजें...',
            prefixIcon: const Icon(Icons.search_rounded, size: 20),
            suffixIcon: _search.isNotEmpty
                ? IconButton(
                    icon: const Icon(Icons.clear_rounded, size: 18),
                    onPressed: () {
                      _searchCtrl.clear();
                      setState(() => _search = '');
                      _fetchListings(reset: true);
                    },
                  )
                : null,
            contentPadding: const EdgeInsets.symmetric(vertical: 8),
            filled: true,
            fillColor: isDark ? AppColors.cardDark : Colors.white,
            border: OutlineInputBorder(
              borderRadius: BorderRadius.circular(24),
              borderSide: BorderSide.none,
            ),
          ),
          onSubmitted: (v) {
            setState(() => _search = v.trim());
            _fetchListings(reset: true);
          },
        ),
      );

  Widget _buildCategoryRow(bool isDark) => SizedBox(
        height: 88,
        child: ListView.builder(
          scrollDirection: Axis.horizontal,
          padding: const EdgeInsets.symmetric(horizontal: 8),
          itemCount: _categories.length,
          itemBuilder: (ctx, i) {
            final (id, label, icon) = _categories[i];
            final sel = _selectedCategoryId == id;
            return GestureDetector(
              onTap: () {
                setState(() => _selectedCategoryId = id);
                _fetchListings(reset: true);
              },
              child: Container(
                width: 72,
                margin: const EdgeInsets.symmetric(horizontal: 4, vertical: 8),
                decoration: BoxDecoration(
                  color: sel
                      ? AppColors.primary.withAlpha(30)
                      : isDark
                          ? AppColors.cardDark
                          : Colors.white,
                  borderRadius: BorderRadius.circular(12),
                  border: Border.all(
                      color: sel
                          ? AppColors.primary
                          : Colors.transparent),
                ),
                child: Column(
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: [
                    Icon(icon,
                        color: sel ? AppColors.primary : Colors.grey,
                        size: 24),
                    const SizedBox(height: 4),
                    Text(label,
                        textAlign: TextAlign.center,
                        style: TextStyle(
                          fontSize: 10,
                          color: sel ? AppColors.primary : null,
                          fontWeight: sel ? FontWeight.bold : FontWeight.normal,
                        ),
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis),
                  ],
                ),
              ),
            );
          },
        ),
      );

  Widget _buildBody(bool isDark) {
    if (_loading) return const Center(child: CircularProgressIndicator());
    if (_listings.isEmpty) {
      return Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(Icons.storefront_outlined, size: 60, color: Colors.grey.shade400),
            const SizedBox(height: 12),
            const Text('कोई विज्ञापन नहीं मिला',
                style: TextStyle(fontSize: 16)),
          ],
        ),
      );
    }
    return RefreshIndicator(
      onRefresh: () => _fetchListings(reset: true),
      child: ListView.builder(
        controller: _scrollCtrl,
        padding: const EdgeInsets.all(12),
        itemCount: _listings.length + (_loadingMore ? 1 : 0),
        itemBuilder: (ctx, i) {
          if (i == _listings.length) {
            return const Padding(
              padding: EdgeInsets.all(16),
              child: Center(child: CircularProgressIndicator()),
            );
          }
          return _ListingCard(
            listing: _listings[i],
            isDark: isDark,
          );
        },
      ),
    );
  }
}

// ── Listing Card ─────────────────────────────────────────────────────────────

class _ListingCard extends StatelessWidget {
  const _ListingCard({required this.listing, required this.isDark});
  final ListingSummary listing;
  final bool isDark;

  @override
  Widget build(BuildContext context) {
    return Card(
      margin: const EdgeInsets.symmetric(vertical: 5),
      color: isDark ? AppColors.cardDark : AppColors.cardLight,
      elevation: 1,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
      child: InkWell(
        borderRadius: BorderRadius.circular(10),
        onTap: () => Navigator.push(
          context,
          MaterialPageRoute(
            builder: (_) => ListingDetailScreen(listingId: listing.id),
          ),
        ),
        child: Padding(
          padding: const EdgeInsets.all(10),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              // Thumbnail
              ClipRRect(
                borderRadius: BorderRadius.circular(8),
                child: listing.thumb != null
                    ? Image.network(
                        listing.thumb!,
                        width: 90,
                        height: 90,
                        fit: BoxFit.cover,
                        errorBuilder: (_, __, ___) => _placeholder(),
                      )
                    : _placeholder(),
              ),
              const SizedBox(width: 10),
              // Info
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    if (listing.isFeatured)
                      Container(
                        padding: const EdgeInsets.symmetric(
                            horizontal: 6, vertical: 2),
                        decoration: BoxDecoration(
                          color: AppColors.accent,
                          borderRadius: BorderRadius.circular(4),
                        ),
                        child: const Text('Featured',
                            style: TextStyle(
                                fontSize: 9,
                                color: Colors.white,
                                fontWeight: FontWeight.bold)),
                      ),
                    const SizedBox(height: 2),
                    Text(listing.title,
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(
                            fontWeight: FontWeight.w600, fontSize: 14)),
                    const SizedBox(height: 4),
                    Text(listing.priceDisplay,
                        style: TextStyle(
                          fontSize: 16,
                          fontWeight: FontWeight.bold,
                          color: AppColors.primary,
                        )),
                    if (listing.priceNegotiable)
                      const Text('(कीमत पर बात हो सकती है)',
                          style: TextStyle(fontSize: 10, color: Colors.grey)),
                    const SizedBox(height: 4),
                    Row(
                      children: [
                        const Icon(Icons.location_on_outlined,
                            size: 12, color: Colors.grey),
                        const SizedBox(width: 2),
                        Expanded(
                          child: Text(listing.city ?? '',
                              style: const TextStyle(
                                  fontSize: 11, color: Colors.grey),
                              overflow: TextOverflow.ellipsis),
                        ),
                        Text(_timeAgo(listing.createdAt),
                            style: const TextStyle(
                                fontSize: 10, color: Colors.grey)),
                      ],
                    ),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _placeholder() => Container(
        width: 90,
        height: 90,
        color: Colors.grey.shade200,
        child: const Icon(Icons.image_outlined, color: Colors.grey, size: 32),
      );

  String _timeAgo(String iso) {
    if (iso.isEmpty) return '';
    final dt = DateTime.tryParse(iso);
    if (dt == null) return '';
    final diff = DateTime.now().difference(dt);
    if (diff.inDays >= 1)  return '${diff.inDays}d ago';
    if (diff.inHours >= 1) return '${diff.inHours}h ago';
    return '${diff.inMinutes}m ago';
  }
}
