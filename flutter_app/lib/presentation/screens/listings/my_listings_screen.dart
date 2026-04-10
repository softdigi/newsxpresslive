import 'package:flutter/material.dart';
import '../../data/models/listing_model.dart';
import '../../data/services/listing_service.dart';
import '../../core/constants/app_colors.dart';
import 'listing_detail_screen.dart';
import 'post_listing_screen.dart';

/// My Listings — shows user's own listings with status tabs.
class MyListingsScreen extends StatefulWidget {
  const MyListingsScreen({super.key});

  @override
  State<MyListingsScreen> createState() => _MyListingsScreenState();
}

class _MyListingsScreenState extends State<MyListingsScreen>
    with SingleTickerProviderStateMixin {
  final _svc = ListingService();
  late TabController _tabCtrl;

  static const _tabs = [
    (null,       'सभी',      Icons.apps_rounded),
    ('active',   'Active',   Icons.check_circle_outline),
    ('pending',  'Pending',  Icons.access_time_rounded),
    ('sold',     'Sold',     Icons.sell_rounded),
    ('expired',  'Expired',  Icons.timer_off_outlined),
  ];

  @override
  void initState() {
    super.initState();
    _tabCtrl = TabController(length: _tabs.length, vsync: this);
  }

  @override
  void dispose() {
    _tabCtrl.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('मेरे विज्ञापन'),
        backgroundColor: AppColors.primary,
        foregroundColor: Colors.white,
        bottom: TabBar(
          controller: _tabCtrl,
          isScrollable: true,
          labelColor: Colors.white,
          unselectedLabelColor: Colors.white60,
          indicatorColor: Colors.white,
          tabs: _tabs
              .map((t) => Tab(
                    child: Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Icon(t.$3, size: 16),
                        const SizedBox(width: 4),
                        Text(t.$2),
                      ],
                    ),
                  ))
              .toList(),
        ),
      ),
      body: TabBarView(
        controller: _tabCtrl,
        children: _tabs
            .map((t) => _ListingsTab(status: t.$1, svc: _svc))
            .toList(),
      ),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: () => Navigator.push(
          context,
          MaterialPageRoute(builder: (_) => const PostListingScreen()),
        ).then((_) => setState(() {})),
        backgroundColor: AppColors.primary,
        icon: const Icon(Icons.add, color: Colors.white),
        label: const Text('नया विज्ञापन', style: TextStyle(color: Colors.white)),
      ),
    );
  }
}

// ── Tab content ───────────────────────────────────────────────────────────────

class _ListingsTab extends StatefulWidget {
  const _ListingsTab({required this.status, required this.svc});
  final String? status;
  final ListingService svc;

  @override
  State<_ListingsTab> createState() => _ListingsTabState();
}

class _ListingsTabState extends State<_ListingsTab>
    with AutomaticKeepAliveClientMixin {
  final _scrollCtrl = ScrollController();
  List<MyListingItem> _items    = [];
  bool _loading    = true;
  bool _loadingMore = false;
  bool _hasMore    = true;
  int  _cursor     = 0;

  @override
  bool get wantKeepAlive => true;

  @override
  void initState() {
    super.initState();
    _scrollCtrl.addListener(_onScroll);
    _fetch(reset: true);
  }

  @override
  void dispose() {
    _scrollCtrl.dispose();
    super.dispose();
  }

  void _onScroll() {
    if (_scrollCtrl.position.pixels >=
            _scrollCtrl.position.maxScrollExtent - 200 &&
        !_loadingMore &&
        _hasMore) {
      _fetch();
    }
  }

  Future<void> _fetch({bool reset = false}) async {
    if (reset) {
      setState(() { _items = []; _cursor = 0; _hasMore = true; _loading = true; });
    } else {
      setState(() => _loadingMore = true);
    }

    final res = await widget.svc.getMyListings(
      firebaseUid: 'guest',
      status: widget.status,
      cursor: _cursor,
    );
    if (mounted) {
      setState(() {
        if (reset) _items = res?.listings ?? [];
        else       _items.addAll(res?.listings ?? []);
        _hasMore     = res?.hasMore ?? false;
        _cursor      = res?.nextCursor ?? 0;
        _loading     = false;
        _loadingMore = false;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    super.build(context);
    if (_loading) return const Center(child: CircularProgressIndicator());
    if (_items.isEmpty) {
      return Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(Icons.storefront_outlined,
                size: 60, color: Colors.grey.shade400),
            const SizedBox(height: 12),
            const Text('कोई विज्ञापन नहीं',
                style: TextStyle(fontSize: 16)),
          ],
        ),
      );
    }

    return RefreshIndicator(
      onRefresh: () => _fetch(reset: true),
      child: ListView.builder(
        controller: _scrollCtrl,
        padding: const EdgeInsets.all(12),
        itemCount: _items.length + (_loadingMore ? 1 : 0),
        itemBuilder: (ctx, i) {
          if (i == _items.length) {
            return const Padding(
                padding: EdgeInsets.all(16),
                child: Center(child: CircularProgressIndicator()));
          }
          return _MyListingCard(
            item: _items[i],
            svc: widget.svc,
            onChanged: () => _fetch(reset: true),
          );
        },
      ),
    );
  }
}

// ── My listing card ───────────────────────────────────────────────────────────

class _MyListingCard extends StatelessWidget {
  const _MyListingCard({
    required this.item,
    required this.svc,
    required this.onChanged,
  });
  final MyListingItem item;
  final ListingService svc;
  final VoidCallback onChanged;

  static const _statusColors = {
    'active':   Colors.green,
    'pending':  Colors.orange,
    'sold':     Colors.blue,
    'expired':  Colors.grey,
    'rejected': Colors.red,
  };

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    return Card(
      margin: const EdgeInsets.symmetric(vertical: 5),
      color: isDark ? AppColors.cardDark : AppColors.cardLight,
      child: InkWell(
        borderRadius: BorderRadius.circular(10),
        onTap: () => Navigator.push(
          context,
          MaterialPageRoute(
            builder: (_) => ListingDetailScreen(listingId: item.id),
          ),
        ),
        child: Padding(
          padding: const EdgeInsets.all(10),
          child: Row(
            children: [
              ClipRRect(
                borderRadius: BorderRadius.circular(8),
                child: item.thumb != null
                    ? Image.network(item.thumb!,
                        width: 80,
                        height: 80,
                        fit: BoxFit.cover,
                        errorBuilder: (_, __, ___) => _placeholder())
                    : _placeholder(),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        Expanded(
                          child: Text(item.title,
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                              style: const TextStyle(fontWeight: FontWeight.w600)),
                        ),
                        Container(
                          padding: const EdgeInsets.symmetric(
                              horizontal: 6, vertical: 2),
                          decoration: BoxDecoration(
                            color: (_statusColors[item.status] ?? Colors.grey)
                                .withAlpha(30),
                            borderRadius: BorderRadius.circular(4),
                          ),
                          child: Text(
                            item.status.toUpperCase(),
                            style: TextStyle(
                              fontSize: 10,
                              color: _statusColors[item.status] ?? Colors.grey,
                              fontWeight: FontWeight.bold,
                            ),
                          ),
                        ),
                      ],
                    ),
                    const SizedBox(height: 4),
                    Text(item.priceDisplay,
                        style: TextStyle(
                            color: AppColors.primary,
                            fontWeight: FontWeight.bold)),
                    const SizedBox(height: 4),
                    Row(
                      children: [
                        const Icon(Icons.remove_red_eye_outlined,
                            size: 12, color: Colors.grey),
                        Text(' ${item.viewsCount}',
                            style: const TextStyle(
                                fontSize: 11, color: Colors.grey)),
                        const SizedBox(width: 10),
                        const Icon(Icons.bookmark_border_rounded,
                            size: 12, color: Colors.grey),
                        Text(' ${item.savesCount}',
                            style: const TextStyle(
                                fontSize: 11, color: Colors.grey)),
                      ],
                    ),
                  ],
                ),
              ),
              // Actions
              PopupMenuButton<String>(
                icon: const Icon(Icons.more_vert_rounded),
                onSelected: (v) => _handleAction(context, v),
                itemBuilder: (_) => [
                  if (item.status != 'sold')
                    const PopupMenuItem(
                        value: 'sold',
                        child: ListTile(
                          leading: Icon(Icons.sell_rounded, size: 18),
                          title: Text('Mark Sold'),
                          dense: true,
                        )),
                  if (item.status != 'active' && item.status != 'pending')
                    const PopupMenuItem(
                        value: 'active',
                        child: ListTile(
                          leading: Icon(Icons.check_circle_outline, size: 18),
                          title: Text('Mark Active'),
                          dense: true,
                        )),
                  const PopupMenuItem(
                      value: 'delete',
                      child: ListTile(
                        leading: Icon(Icons.delete_outline_rounded,
                            size: 18, color: Colors.red),
                        title: Text('Delete',
                            style: TextStyle(color: Colors.red)),
                        dense: true,
                      )),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _placeholder() => Container(
        width: 80,
        height: 80,
        color: Colors.grey.shade200,
        child: const Icon(Icons.image_outlined, color: Colors.grey),
      );

  Future<void> _handleAction(BuildContext context, String action) async {
    if (action == 'delete') {
      final confirm = await showDialog<bool>(
        context: context,
        builder: (ctx) => AlertDialog(
          title: const Text('विज्ञापन हटाएं?'),
          content: const Text('क्या आप इस विज्ञापन को हटाना चाहते हैं?'),
          actions: [
            TextButton(
                onPressed: () => Navigator.pop(ctx, false),
                child: const Text('रद्द करें')),
            FilledButton(
                onPressed: () => Navigator.pop(ctx, true),
                style: FilledButton.styleFrom(backgroundColor: Colors.red),
                child: const Text('हटाएं')),
          ],
        ),
      );
      if (confirm == true) {
        await svc.deleteListing(firebaseUid: 'guest', id: item.id);
        onChanged();
      }
    } else {
      await svc.updateListingStatus(
          firebaseUid: 'guest', id: item.id, status: action);
      onChanged();
    }
  }
}
