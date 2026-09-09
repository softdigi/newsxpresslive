import 'package:flutter/material.dart';
import 'package:timeago/timeago.dart' as timeago;
import '../../../core/constants/app_colors.dart';
import '../../../core/constants/app_strings.dart';
import '../../../data/models/complaint_model.dart';
import '../../../data/services/complaint_service.dart';
import 'submit_complaint_screen.dart';

/// Lists approved public complaints, grouped/filtered by category.
class ComplaintsScreen extends StatefulWidget {
  const ComplaintsScreen({super.key});

  @override
  State<ComplaintsScreen> createState() => _ComplaintsScreenState();
}

class _ComplaintsScreenState extends State<ComplaintsScreen> {
  final _service = ComplaintService();

  final List<ComplaintModel>    _items      = [];
  final List<ComplaintCategory> _categories = [];
  String?  _activeCategory;
  int      _page    = 1;
  bool     _loading = false;
  bool     _hasMore = true;
  bool     _initErr = false;

  @override
  void initState() {
    super.initState();
    _loadCategories();
    _loadMore();
  }

  @override
  void dispose() {
    _service.dispose();
    super.dispose();
  }

  Future<void> _loadCategories() async {
    final cats = await _service.getCategories();
    if (mounted) setState(() => _categories.addAll(cats));
  }

  Future<void> _loadMore() async {
    if (_loading || !_hasMore) return;
    setState(() => _loading = true);
    try {
      final page = await _service.getComplaints(
        page:     _page,
        category: _activeCategory,
      );
      if (!page.hasMore) _hasMore = false;
      _page++;
      _items.addAll(page.items);
    } catch (_) {
      if (_items.isEmpty) _initErr = true;
    }
    if (mounted) setState(() => _loading = false);
  }

  Future<void> _refresh() async {
    setState(() {
      _items.clear();
      _page    = 1;
      _hasMore = true;
      _initErr = false;
    });
    await _loadMore();
  }

  void _setCategory(String? slug) {
    if (_activeCategory == slug) return;
    setState(() {
      _activeCategory = slug;
      _items.clear();
      _page    = 1;
      _hasMore = true;
      _initErr = false;
    });
    _loadMore();
  }

  void _onVoteChanged(int index, int count, bool voted) {
    setState(() {
      _items[index] = _items[index].copyWith(
        votesCount: count,
        userVoted:  voted,
      );
    });
  }

  @override
  Widget build(BuildContext context) {
    final cs = Theme.of(context).colorScheme;
    return Scaffold(
      appBar: AppBar(
        title: const Text(AppStrings.complaintsTitle),
        backgroundColor: cs.surface,
        elevation: 0,
      ),
      floatingActionButton: FloatingActionButton.extended(
        backgroundColor: AppColors.primary,
        icon: const Icon(Icons.add, color: Colors.white),
        label: Text(AppStrings.complaintSubmitBtn,
            style: const TextStyle(color: Colors.white)),
        onPressed: () => Navigator.push(
          context,
          MaterialPageRoute(
            builder: (_) => SubmitComplaintScreen(
              categories: _categories,
              onSubmitted: _refresh,
            ),
          ),
        ),
      ),
      body: Column(
        children: [
          // ── Category chip row ──────────────────────────────────────
          _CategoryBar(
            categories:     _categories,
            activeSlug:     _activeCategory,
            onSelected:     _setCategory,
          ),
          // ── Complaint list ─────────────────────────────────────────
          Expanded(
            child: _buildList(),
          ),
        ],
      ),
    );
  }

  Widget _buildList() {
    if (_loading && _items.isEmpty) {
      return const Center(
        child: CircularProgressIndicator(color: AppColors.primary),
      );
    }
    if (_initErr || _items.isEmpty) {
      return Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Icon(Icons.campaign_outlined,
                size: 64, color: Colors.grey),
            const SizedBox(height: 12),
            Text(
              _initErr
                  ? AppStrings.loadingFailed
                  : AppStrings.complaintNoItems,
              textAlign: TextAlign.center,
              style: const TextStyle(color: Colors.grey),
            ),
            if (_initErr) ...[
              const SizedBox(height: 12),
              ElevatedButton(
                onPressed: () {
                  setState(() => _initErr = false);
                  _loadMore();
                },
                child: const Text(AppStrings.retry),
              ),
            ],
          ],
        ),
      );
    }
    return RefreshIndicator(
      color:     AppColors.primary,
      onRefresh: _refresh,
      child: ListView.builder(
        padding:     const EdgeInsets.fromLTRB(12, 8, 12, 100),
        itemCount:   _items.length + (_hasMore ? 1 : 0),
        itemBuilder: (_, i) {
          if (i == _items.length) {
            if (!_loading) _loadMore();
            return const Padding(
              padding: EdgeInsets.all(16),
              child: Center(
                child: CircularProgressIndicator(color: AppColors.primary),
              ),
            );
          }
          return _ComplaintCard(
            complaint: _items[i],
            service:   _service,
            onVoteChanged: (c, v) => _onVoteChanged(i, c, v),
          );
        },
      ),
    );
  }
}

// ── Category filter bar ───────────────────────────────────────────────────────

class _CategoryBar extends StatelessWidget {
  const _CategoryBar({
    required this.categories,
    required this.activeSlug,
    required this.onSelected,
  });

  final List<ComplaintCategory> categories;
  final String?                 activeSlug;
  final void Function(String?)  onSelected;

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      height: 42,
      child: ListView(
        scrollDirection: Axis.horizontal,
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 4),
        children: [
          _chip('All', null, activeSlug == null),
          ...categories.map((c) => _chip(c.name, c.slug, activeSlug == c.slug)),
        ],
      ),
    );
  }

  Widget _chip(String label, String? slug, bool active) {
    return Padding(
      padding: const EdgeInsets.only(right: 8),
      child: FilterChip(
        label: Text(label, style: TextStyle(
            color: active ? Colors.white : AppColors.primary, fontSize: 12)),
        selected:         active,
        onSelected:       (_) => onSelected(slug),
        backgroundColor:  AppColors.chipBackground,
        selectedColor:    AppColors.primary,
        checkmarkColor:   Colors.white,
        side:             const BorderSide(color: AppColors.primary, width: 0.5),
        padding:          const EdgeInsets.symmetric(horizontal: 4),
        visualDensity:    VisualDensity.compact,
      ),
    );
  }
}

// ── Complaint card ────────────────────────────────────────────────────────────

class _ComplaintCard extends StatefulWidget {
  const _ComplaintCard({
    required this.complaint,
    required this.service,
    required this.onVoteChanged,
  });

  final ComplaintModel   complaint;
  final ComplaintService service;
  final void Function(int count, bool voted) onVoteChanged;

  @override
  State<_ComplaintCard> createState() => _ComplaintCardState();
}

class _ComplaintCardState extends State<_ComplaintCard> {
  bool _voting = false;

  Future<void> _vote() async {
    if (_voting) return;
    setState(() => _voting = true);
    final r = await widget.service.vote(widget.complaint.id);
    if (mounted) setState(() => _voting = false);
    if (r != null && r['success'] == true) {
      final voted = r['voted'] as bool? ?? !widget.complaint.userVoted;
      final count = r['votes_count'] is int
          ? r['votes_count'] as int
          : widget.complaint.votesCount + (voted ? 1 : -1);
      widget.onVoteChanged(count, voted);
    }
  }

  @override
  Widget build(BuildContext context) {
    final c    = widget.complaint;
    final dark = Theme.of(context).brightness == Brightness.dark;

    return Card(
      margin: const EdgeInsets.only(bottom: 12),
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
      color: dark ? AppColors.cardDark : AppColors.cardLight,
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            // ── Header row ────────────────────────────────────────────
            Row(
              children: [
                _StatusBadge(status: c.status),
                const SizedBox(width: 6),
                Expanded(
                  child: Text(
                    c.category.name,
                    style: TextStyle(
                      fontSize: 11,
                      color: dark
                          ? AppColors.textSecondaryDark
                          : AppColors.textSecondaryLight,
                    ),
                  ),
                ),
                Text(
                  timeago.format(DateTime.tryParse(c.createdAt) ?? DateTime.now()),
                  style: TextStyle(
                    fontSize: 11,
                    color: dark
                        ? AppColors.textSecondaryDark
                        : AppColors.textSecondaryLight,
                  ),
                ),
              ],
            ),
            const SizedBox(height: 8),
            // ── Title ─────────────────────────────────────────────────
            Text(
              c.title,
              style: TextStyle(
                fontWeight: FontWeight.bold,
                fontSize:   15,
                color: dark
                    ? AppColors.textPrimaryDark
                    : AppColors.textPrimaryLight,
              ),
            ),
            const SizedBox(height: 4),
            // ── Description (truncated) ───────────────────────────────
            Text(
              c.description,
              maxLines:  3,
              overflow:  TextOverflow.ellipsis,
              style: TextStyle(
                fontSize: 13,
                color: dark
                    ? AppColors.textSecondaryDark
                    : AppColors.textSecondaryLight,
              ),
            ),
            // ── Photo thumbnail ───────────────────────────────────────
            if (c.photo != null && c.photo!.isNotEmpty) ...[
              const SizedBox(height: 8),
              ClipRRect(
                borderRadius: BorderRadius.circular(6),
                child: Image.network(
                  c.photo!,
                  height: 160,
                  width: double.infinity,
                  fit: BoxFit.cover,
                  errorBuilder: (_, __, ___) => const SizedBox.shrink(),
                ),
              ),
            ],
            const SizedBox(height: 8),
            // ── Footer: location + vote ───────────────────────────────
            Row(
              children: [
                if (c.districtName != null || c.locationText != null) ...[
                  const Icon(Icons.location_on_outlined,
                      size: 14, color: Colors.grey),
                  const SizedBox(width: 2),
                  Expanded(
                    child: Text(
                      c.districtName ?? c.locationText ?? '',
                      style: const TextStyle(fontSize: 11, color: Colors.grey),
                      overflow: TextOverflow.ellipsis,
                    ),
                  ),
                ] else
                  const Spacer(),
                _VoteButton(
                  count:  c.votesCount,
                  voted:  c.userVoted,
                  voting: _voting,
                  onTap:  _vote,
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

class _StatusBadge extends StatelessWidget {
  const _StatusBadge({required this.status});
  final String status;

  @override
  Widget build(BuildContext context) {
    final (label, color) = switch (status) {
      'resolved'    => (AppStrings.complaintStatusResolved, Colors.green),
      'under_review'=> (AppStrings.complaintStatusReview,   Colors.orange),
      _             => (AppStrings.complaintStatusOpen,     AppColors.primary),
    };
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
      decoration: BoxDecoration(
        color: color.withOpacity(0.1),
        border: Border.all(color: color, width: 0.7),
        borderRadius: BorderRadius.circular(4),
      ),
      child: Text(label,
          style: TextStyle(
              color: color, fontSize: 10, fontWeight: FontWeight.w600)),
    );
  }
}

class _VoteButton extends StatelessWidget {
  const _VoteButton({
    required this.count,
    required this.voted,
    required this.voting,
    required this.onTap,
  });

  final int  count;
  final bool voted;
  final bool voting;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTap: voting ? null : onTap,
      child: AnimatedContainer(
        duration: const Duration(milliseconds: 200),
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
        decoration: BoxDecoration(
          color: voted ? AppColors.primary : Colors.transparent,
          border: Border.all(color: AppColors.primary),
          borderRadius: BorderRadius.circular(20),
        ),
        child: voting
            ? const SizedBox(
                width: 14, height: 14,
                child: CircularProgressIndicator(
                    strokeWidth: 1.5,
                    color: AppColors.primary))
            : Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Icon(
                    voted
                        ? Icons.thumb_up_rounded
                        : Icons.thumb_up_outlined,
                    size:  14,
                    color: voted ? Colors.white : AppColors.primary,
                  ),
                  const SizedBox(width: 4),
                  Text(
                    '$count',
                    style: TextStyle(
                      fontSize: 12,
                      fontWeight: FontWeight.w600,
                      color: voted ? Colors.white : AppColors.primary,
                    ),
                  ),
                ],
              ),
      ),
    );
  }
}
