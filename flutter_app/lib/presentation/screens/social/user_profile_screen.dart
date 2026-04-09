import 'package:flutter/material.dart';
import '../../../core/constants/app_colors.dart';
import '../../../core/constants/app_strings.dart';
import '../../../data/models/social_model.dart';
import '../../../data/services/social_service.dart';
import '../../../data/services/api_service.dart';

/// Displays a public user / reporter profile with:
///   - Avatar, display name, verified badge
///   - Followers / Following counts
///   - Follow / Unfollow button (requires authenticated [SocialService])
///
/// Usage:
///   Navigator.push(
///     context,
///     MaterialPageRoute(
///       builder: (_) => UserProfileScreen(
///         uid:          'firebase_uid_here',
///         displayName:  'Reporter Name',
///         avatarUrl:    null,
///         isVerified:   true,
///         socialService: socialService,  // pre-built with idTokenProvider
///       ),
///     ),
///   );
class UserProfileScreen extends StatefulWidget {
  const UserProfileScreen({
    super.key,
    required this.uid,
    this.displayName,
    this.avatarUrl,
    this.isReporter = false,
    this.isVerified = false,
    required this.socialService,
  });

  final String uid;
  final String? displayName;
  final String? avatarUrl;
  final bool isReporter;
  final bool isVerified;

  /// [SocialService] must be backed by an [ApiService] with
  /// [ApiService.idTokenProvider] set for the follow button to work.
  final SocialService socialService;

  @override
  State<UserProfileScreen> createState() => _UserProfileScreenState();
}

class _UserProfileScreenState extends State<UserProfileScreen>
    with SingleTickerProviderStateMixin {
  late TabController _tabController;

  // state
  FollowCounts _counts     = FollowCounts.empty('');
  bool         _isFollowing = false;
  bool         _loadingFollow = false;
  bool         _loadingCounts = true;

  // follower / following lists (lazy-loaded by tab)
  List<SocialUser> _followers = [];
  List<SocialUser> _following = [];
  bool _loadingFollowers = false;
  bool _loadingFollowing = false;

  @override
  void initState() {
    super.initState();
    _tabController = TabController(length: 2, vsync: this);
    _tabController.addListener(_onTabChange);
    _loadInitial();
  }

  Future<void> _loadInitial() async {
    final futures = await Future.wait([
      widget.socialService.getFollowCounts(widget.uid),
      widget.socialService.isFollowing(widget.uid),
    ]);
    if (!mounted) return;
    setState(() {
      _counts      = futures[0] as FollowCounts;
      _isFollowing = futures[1] as bool;
      _loadingCounts = false;
    });
    // preload first tab
    _loadFollowers();
  }

  void _onTabChange() {
    if (!_tabController.indexIsChanging) return;
    if (_tabController.index == 0 && _followers.isEmpty) _loadFollowers();
    if (_tabController.index == 1 && _following.isEmpty) _loadFollowing();
  }

  Future<void> _loadFollowers() async {
    if (_loadingFollowers) return;
    setState(() => _loadingFollowers = true);
    final list = await widget.socialService.getFollowers(widget.uid);
    if (!mounted) return;
    setState(() {
      _followers = list;
      _loadingFollowers = false;
    });
  }

  Future<void> _loadFollowing() async {
    if (_loadingFollowing) return;
    setState(() => _loadingFollowing = true);
    final list = await widget.socialService.getFollowing(widget.uid);
    if (!mounted) return;
    setState(() {
      _following = list;
      _loadingFollowing = false;
    });
  }

  Future<void> _handleFollow() async {
    if (_loadingFollow) return;
    setState(() => _loadingFollow = true);
    final result = await widget.socialService.toggleFollow(widget.uid);
    if (!mounted) return;
    if (result != null) {
      setState(() {
        _isFollowing = result['is_following'] as bool? ?? _isFollowing;
        _counts = FollowCounts(
          uid:            widget.uid,
          followersCount: result['followers_count'] as int? ?? _counts.followersCount,
          followingCount: _counts.followingCount,
        );
      });
    }
    setState(() => _loadingFollow = false);
  }

  @override
  void dispose() {
    _tabController
      ..removeListener(_onTabChange)
      ..dispose();
    super.dispose();
  }

  // ── Build ──────────────────────────────────────────────────────────────────

  @override
  Widget build(BuildContext context) {
    final theme      = Theme.of(context);
    final isDark     = theme.brightness == Brightness.dark;
    final name       = widget.displayName ?? widget.uid;

    return Scaffold(
      appBar: AppBar(
        title: Text(widget.isReporter
            ? AppStrings.socialReporterProfile
            : AppStrings.socialUserProfile),
        backgroundColor: AppColors.primary,
        foregroundColor: Colors.white,
        elevation: 0,
      ),
      body: Column(
        children: [
          // ── Profile header ─────────────────────────────────────────────
          Container(
            color: AppColors.primary,
            padding: const EdgeInsets.fromLTRB(16, 0, 16, 24),
            child: Column(
              children: [
                // Avatar
                CircleAvatar(
                  radius: 42,
                  backgroundColor: Colors.white24,
                  backgroundImage: widget.avatarUrl != null
                      ? NetworkImage(widget.avatarUrl!) as ImageProvider
                      : null,
                  child: widget.avatarUrl == null
                      ? Text(
                          _initials(name),
                          style: const TextStyle(
                            color: Colors.white,
                            fontSize: 28,
                            fontWeight: FontWeight.bold,
                          ),
                        )
                      : null,
                ),
                const SizedBox(height: 10),

                // Name + verified badge
                Row(
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: [
                    Flexible(
                      child: Text(
                        name,
                        style: const TextStyle(
                          color: Colors.white,
                          fontSize: 20,
                          fontWeight: FontWeight.bold,
                        ),
                        overflow: TextOverflow.ellipsis,
                      ),
                    ),
                    if (widget.isVerified) ...[
                      const SizedBox(width: 6),
                      const Icon(Icons.verified_rounded,
                          color: Colors.white, size: 18),
                    ],
                  ],
                ),

                if (widget.isReporter) ...[
                  const SizedBox(height: 4),
                  Container(
                    padding:
                        const EdgeInsets.symmetric(horizontal: 10, vertical: 2),
                    decoration: BoxDecoration(
                      color: Colors.white24,
                      borderRadius: BorderRadius.circular(12),
                    ),
                    child: const Text(
                      'Reporter',
                      style:
                          TextStyle(color: Colors.white70, fontSize: 12),
                    ),
                  ),
                ],

                const SizedBox(height: 16),

                // Counts row
                _loadingCounts
                    ? const CircularProgressIndicator(
                        color: Colors.white, strokeWidth: 2)
                    : Row(
                        mainAxisAlignment: MainAxisAlignment.center,
                        children: [
                          _CountChip(
                            label: AppStrings.socialFollowers,
                            count: _counts.followersCount,
                          ),
                          const SizedBox(width: 32),
                          _CountChip(
                            label: AppStrings.socialFollowing,
                            count: _counts.followingCount,
                          ),
                        ],
                      ),

                const SizedBox(height: 16),

                // Follow button
                SizedBox(
                  width: 140,
                  child: ElevatedButton(
                    onPressed: _loadingFollow ? null : _handleFollow,
                    style: ElevatedButton.styleFrom(
                      backgroundColor:
                          _isFollowing ? Colors.white24 : Colors.white,
                      foregroundColor:
                          _isFollowing ? Colors.white : AppColors.primary,
                      shape: RoundedRectangleBorder(
                        borderRadius: BorderRadius.circular(20),
                        side: BorderSide(
                            color: _isFollowing
                                ? Colors.white54
                                : Colors.transparent),
                      ),
                      padding: const EdgeInsets.symmetric(vertical: 8),
                    ),
                    child: _loadingFollow
                        ? const SizedBox(
                            width: 16,
                            height: 16,
                            child: CircularProgressIndicator(strokeWidth: 2),
                          )
                        : Text(
                            _isFollowing
                                ? AppStrings.socialUnfollow
                                : AppStrings.socialFollow,
                            style: const TextStyle(fontWeight: FontWeight.bold),
                          ),
                  ),
                ),
              ],
            ),
          ),

          // ── Tabs ───────────────────────────────────────────────────────
          TabBar(
            controller: _tabController,
            labelColor: AppColors.primary,
            unselectedLabelColor:
                isDark ? Colors.white60 : Colors.black54,
            indicatorColor: AppColors.primary,
            tabs: [
              Tab(text: AppStrings.socialFollowers),
              Tab(text: AppStrings.socialFollowing),
            ],
          ),

          // ── Tab content ────────────────────────────────────────────────
          Expanded(
            child: TabBarView(
              controller: _tabController,
              children: [
                _UserList(
                  users:   _followers,
                  loading: _loadingFollowers,
                  emptyLabel: AppStrings.socialNoFollowers,
                ),
                _UserList(
                  users:   _following,
                  loading: _loadingFollowing,
                  emptyLabel: AppStrings.socialNoFollowing,
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  String _initials(String name) {
    final parts = name.trim().split(RegExp(r'\s+'));
    if (parts.length >= 2) {
      return '${parts.first[0]}${parts.last[0]}'.toUpperCase();
    }
    return name.isNotEmpty ? name[0].toUpperCase() : '?';
  }
}

// ─────────────────────────────────────────────────────────────
// Private widgets
// ─────────────────────────────────────────────────────────────

class _CountChip extends StatelessWidget {
  const _CountChip({required this.label, required this.count});
  final String label;
  final int count;

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        Text(
          _format(count),
          style: const TextStyle(
              color: Colors.white,
              fontSize: 20,
              fontWeight: FontWeight.bold),
        ),
        Text(label,
            style: const TextStyle(color: Colors.white70, fontSize: 13)),
      ],
    );
  }

  String _format(int n) {
    if (n >= 1000000) return '${(n / 1000000).toStringAsFixed(1)}M';
    if (n >= 1000) return '${(n / 1000).toStringAsFixed(1)}K';
    return '$n';
  }
}

class _UserList extends StatelessWidget {
  const _UserList({
    required this.users,
    required this.loading,
    required this.emptyLabel,
  });

  final List<SocialUser> users;
  final bool loading;
  final String emptyLabel;

  @override
  Widget build(BuildContext context) {
    if (loading) {
      return const Center(child: CircularProgressIndicator());
    }
    if (users.isEmpty) {
      return Center(
        child: Text(emptyLabel,
            style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                  color: Colors.grey,
                )),
      );
    }
    return ListView.separated(
      padding: const EdgeInsets.symmetric(vertical: 8),
      itemCount: users.length,
      separatorBuilder: (_, __) => const Divider(height: 1, indent: 72),
      itemBuilder: (context, i) => _UserTile(user: users[i]),
    );
  }
}

class _UserTile extends StatelessWidget {
  const _UserTile({required this.user});
  final SocialUser user;

  @override
  Widget build(BuildContext context) {
    final name = user.displayName ?? user.firebaseUid;
    return ListTile(
      leading: CircleAvatar(
        radius: 22,
        backgroundColor: AppColors.primary.withOpacity(0.12),
        backgroundImage: user.avatarUrl != null
            ? NetworkImage(user.avatarUrl!) as ImageProvider
            : null,
        child: user.avatarUrl == null
            ? Text(
                user.initials,
                style: const TextStyle(
                    color: AppColors.primary,
                    fontWeight: FontWeight.bold),
              )
            : null,
      ),
      title: Row(
        children: [
          Flexible(
            child: Text(name, overflow: TextOverflow.ellipsis),
          ),
          if (user.isVerified) ...[
            const SizedBox(width: 4),
            const Icon(Icons.verified_rounded,
                color: AppColors.primary, size: 14),
          ],
        ],
      ),
      subtitle: user.isReporter
          ? const Text('Reporter',
              style: TextStyle(color: AppColors.primary, fontSize: 12))
          : null,
    );
  }
}
