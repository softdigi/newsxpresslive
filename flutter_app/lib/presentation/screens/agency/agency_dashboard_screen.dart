import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../../core/constants/app_colors.dart';
import '../../../data/models/agency_models.dart';
import '../../../providers/agency_provider.dart';
import 'agency_analytics_screen.dart';
import 'agency_articles_screen.dart';
import 'agency_submit_screen.dart';
import 'agency_bulk_upload_screen.dart';
import 'agency_wallet_screen.dart';
import 'agency_api_docs_screen.dart';
import 'agency_login_screen.dart';

class AgencyDashboardScreen extends StatefulWidget {
  const AgencyDashboardScreen({super.key});

  @override
  State<AgencyDashboardScreen> createState() => _AgencyDashboardScreenState();
}

class _AgencyDashboardScreenState extends State<AgencyDashboardScreen> {
  int _tabIndex = 0;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      context.read<AgencyProvider>().loadDashboard();
    });
  }

  final List<Widget> _screens = const [
    _DashboardBody(),
    AgencyArticlesScreen(),
    AgencyAnalyticsScreen(),
    AgencyWalletScreen(),
  ];

  void _openMore(BuildContext context) {
    showModalBottomSheet(
      context: context,
      shape: const RoundedRectangleBorder(
          borderRadius: BorderRadius.vertical(top: Radius.circular(16))),
      builder: (_) => _MoreSheet(),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: IndexedStack(
        index: _tabIndex < 4 ? _tabIndex : 0,
        children: _screens,
      ),
      bottomNavigationBar: BottomNavigationBar(
        currentIndex: _tabIndex < 4 ? _tabIndex : 0,
        onTap: (i) {
          if (i == 4) {
            _openMore(context);
          } else {
            setState(() => _tabIndex = i);
          }
        },
        type: BottomNavigationBarType.fixed,
        selectedItemColor: AppColors.primary,
        unselectedItemColor: AppColors.textSecondaryLight,
        items: const [
          BottomNavigationBarItem(
              icon: Icon(Icons.dashboard_outlined), label: 'Dashboard'),
          BottomNavigationBarItem(
              icon: Icon(Icons.article_outlined), label: 'Articles'),
          BottomNavigationBarItem(
              icon: Icon(Icons.bar_chart_outlined), label: 'Analytics'),
          BottomNavigationBarItem(
              icon: Icon(Icons.account_balance_wallet_outlined),
              label: 'Wallet'),
          BottomNavigationBarItem(
              icon: Icon(Icons.more_horiz), label: 'More'),
        ],
      ),
    );
  }
}

// ── More sheet ────────────────────────────────────────────────────────────────

class _MoreSheet extends StatelessWidget {
  @override
  Widget build(BuildContext context) {
    return SafeArea(
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: 8),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            ListTile(
              leading:
                  const Icon(Icons.add_circle_outline, color: AppColors.primary),
              title: const Text('Submit Article'),
              onTap: () {
                Navigator.pop(context);
                Navigator.push(context,
                    MaterialPageRoute(builder: (_) => const AgencySubmitScreen()));
              },
            ),
            ListTile(
              leading:
                  const Icon(Icons.upload_file_outlined, color: AppColors.primary),
              title: const Text('Bulk Upload'),
              onTap: () {
                Navigator.pop(context);
                Navigator.push(
                    context,
                    MaterialPageRoute(
                        builder: (_) => const AgencyBulkUploadScreen()));
              },
            ),
            ListTile(
              leading:
                  const Icon(Icons.code_outlined, color: AppColors.primary),
              title: const Text('API Docs'),
              onTap: () {
                Navigator.pop(context);
                Navigator.push(
                    context,
                    MaterialPageRoute(
                        builder: (_) => const AgencyApiDocsScreen()));
              },
            ),
          ],
        ),
      ),
    );
  }
}

// ── Dashboard body ────────────────────────────────────────────────────────────

class _DashboardBody extends StatelessWidget {
  const _DashboardBody();

  @override
  Widget build(BuildContext context) {
    return Consumer<AgencyProvider>(
      builder: (context, provider, _) {
        return Scaffold(
          backgroundColor: AppColors.scaffoldLight,
          appBar: AppBar(
            backgroundColor: AppColors.primary,
            foregroundColor: Colors.white,
            title: const Text('Agency Dashboard'),
            actions: [
              IconButton(
                icon: const Icon(Icons.logout),
                tooltip: 'Logout',
                onPressed: () async {
                  await provider.logout();
                  if (context.mounted) {
                    Navigator.of(context).pushAndRemoveUntil(
                      MaterialPageRoute(
                          builder: (_) => const AgencyLoginScreen()),
                      (_) => false,
                    );
                  }
                },
              ),
            ],
          ),
          body: provider.isLoading && provider.agencyProfile == null
              ? const Center(child: CircularProgressIndicator())
              : provider.errorMsg != null && provider.agencyProfile == null
                  ? Center(
                      child: Column(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          Text(provider.errorMsg!,
                              style:
                                  const TextStyle(color: AppColors.primary)),
                          const SizedBox(height: 12),
                          ElevatedButton(
                            onPressed: () => provider.loadDashboard(
                                forceRefresh: true),
                            child: const Text('Retry'),
                          ),
                        ],
                      ),
                    )
                  : RefreshIndicator(
                      onRefresh: () =>
                          provider.loadDashboard(forceRefresh: true),
                      child: ListView(
                        padding: const EdgeInsets.all(16),
                        children: [
                          if (provider.agencyProfile != null) ...[
                            _StatsGrid(provider.agencyProfile!),
                            const SizedBox(height: 16),
                            _RevenueCard(provider.agencyProfile!),
                            const SizedBox(height: 16),
                            _RevenueBarChart(provider.articles),
                            const SizedBox(height: 16),
                            _RecentArticles(provider.articles),
                          ],
                        ],
                      ),
                    ),
        );
      },
    );
  }
}

// ── Stats grid ────────────────────────────────────────────────────────────────

class _StatsGrid extends StatelessWidget {
  const _StatsGrid(this.profile);
  final AgencyProfile profile;

  @override
  Widget build(BuildContext context) {
    final stats = [
      _Stat('Total', profile.articlesTotal, AppColors.textPrimaryLight),
      _Stat('Approved', profile.articlesApproved, Colors.green),
      _Stat('Pending', profile.articlesPending, AppColors.accent),
      _Stat('Rejected', profile.articlesRejected, AppColors.primary),
    ];
    return GridView.count(
      crossAxisCount: 2,
      crossAxisSpacing: 12,
      mainAxisSpacing: 12,
      childAspectRatio: 1.7,
      shrinkWrap: true,
      physics: const NeverScrollableScrollPhysics(),
      children: stats.map((s) => _StatCard(s)).toList(),
    );
  }
}

class _Stat {
  final String label;
  final int value;
  final Color color;
  const _Stat(this.label, this.value, this.color);
}

class _StatCard extends StatelessWidget {
  const _StatCard(this.stat);
  final _Stat stat;

  @override
  Widget build(BuildContext context) {
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
      child: Column(
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          Text(
            stat.value.toString(),
            style: TextStyle(
                fontSize: 26,
                fontWeight: FontWeight.bold,
                color: stat.color),
          ),
          const SizedBox(height: 4),
          Text(stat.label,
              style: const TextStyle(
                  fontSize: 13, color: AppColors.textSecondaryLight)),
        ],
      ),
    );
  }
}

// ── Revenue card ──────────────────────────────────────────────────────────────

class _RevenueCard extends StatelessWidget {
  const _RevenueCard(this.profile);
  final AgencyProfile profile;

  @override
  Widget build(BuildContext context) {
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
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Text('Revenue',
              style: TextStyle(
                  fontSize: 16,
                  fontWeight: FontWeight.bold,
                  color: AppColors.textPrimaryLight)),
          const SizedBox(height: 12),
          Row(
            children: [
              const Text('Wallet Balance',
                  style: TextStyle(color: AppColors.textSecondaryLight)),
              const Spacer(),
              Text(
                '₹${profile.walletBalance.toStringAsFixed(2)}',
                style: const TextStyle(
                    fontSize: 22,
                    fontWeight: FontWeight.bold,
                    color: Colors.green),
              ),
            ],
          ),
          const Divider(height: 20),
          Row(
            children: [
              _RevStat('This Month',
                  '₹${profile.thisMonthEarned.toStringAsFixed(2)}'),
              const SizedBox(width: 24),
              _RevStat(
                  'Lifetime', '₹${profile.totalEarned.toStringAsFixed(2)}'),
            ],
          ),
          if (profile.walletBalance >= 500) ...[
            const SizedBox(height: 12),
            SizedBox(
              width: double.infinity,
              child: ElevatedButton.icon(
                onPressed: () => Navigator.push(
                  context,
                  MaterialPageRoute(
                      builder: (_) => const AgencyWalletScreen()),
                ),
                icon: const Icon(Icons.account_balance_wallet_outlined),
                label: const Text('Withdraw'),
                style: ElevatedButton.styleFrom(
                  backgroundColor: Colors.green,
                  foregroundColor: Colors.white,
                  shape: RoundedRectangleBorder(
                      borderRadius: BorderRadius.circular(10)),
                ),
              ),
            ),
          ],
        ],
      ),
    );
  }
}

class _RevStat extends StatelessWidget {
  const _RevStat(this.label, this.value);
  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(label,
            style: const TextStyle(
                fontSize: 12, color: AppColors.textSecondaryLight)),
        Text(value,
            style: const TextStyle(
                fontSize: 15, fontWeight: FontWeight.w600)),
      ],
    );
  }
}

// ── Simple 7-day bar chart ────────────────────────────────────────────────────

class _RevenueBarChart extends StatelessWidget {
  const _RevenueBarChart(this.articles);
  final List<AgencyArticle> articles;

  @override
  Widget build(BuildContext context) {
    // Bucket articles by day offset for last 7 days
    final now = DateTime.now();
    final buckets = List<double>.filled(7, 0);
    for (final a in articles) {
      final diff = now.difference(a.submittedAt).inDays;
      if (diff >= 0 && diff < 7) {
        buckets[6 - diff] += a.revenueEarned;
      }
    }
    final maxVal = buckets.fold<double>(0, (p, v) => v > p ? v : p);
    const barMaxHeight = 60.0;

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
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Text('7-Day Revenue',
              style: TextStyle(
                  fontSize: 15,
                  fontWeight: FontWeight.bold,
                  color: AppColors.textPrimaryLight)),
          const SizedBox(height: 12),
          Row(
            crossAxisAlignment: CrossAxisAlignment.end,
            mainAxisAlignment: MainAxisAlignment.spaceEvenly,
            children: List.generate(7, (i) {
              final height = maxVal > 0
                  ? (buckets[i] / maxVal) * barMaxHeight
                  : 4.0;
              final day =
                  now.subtract(Duration(days: 6 - i));
              final label =
                  '${day.month}/${day.day}';
              return Column(
                mainAxisAlignment: MainAxisAlignment.end,
                children: [
                  Container(
                    width: 28,
                    height: height.clamp(4, barMaxHeight),
                    decoration: BoxDecoration(
                      color: AppColors.primary.withOpacity(0.8),
                      borderRadius: BorderRadius.circular(4),
                    ),
                  ),
                  const SizedBox(height: 4),
                  Text(label,
                      style: const TextStyle(
                          fontSize: 9,
                          color: AppColors.textSecondaryLight)),
                ],
              );
            }),
          ),
        ],
      ),
    );
  }
}

// ── Recent articles ───────────────────────────────────────────────────────────

class _RecentArticles extends StatelessWidget {
  const _RecentArticles(this.articles);
  final List<AgencyArticle> articles;

  @override
  Widget build(BuildContext context) {
    final recent = articles.take(5).toList();
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
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Text('Recent Articles',
              style: TextStyle(
                  fontSize: 15,
                  fontWeight: FontWeight.bold,
                  color: AppColors.textPrimaryLight)),
          const SizedBox(height: 8),
          if (recent.isEmpty)
            const Padding(
              padding: EdgeInsets.symmetric(vertical: 16),
              child: Center(
                  child: Text('No articles yet',
                      style:
                          TextStyle(color: AppColors.textSecondaryLight))),
            )
          else
            ...recent.map((a) => _ArticleRow(a)),
        ],
      ),
    );
  }
}

class _ArticleRow extends StatelessWidget {
  const _ArticleRow(this.article);
  final AgencyArticle article;

  Color _badgeColor(String s) {
    switch (s) {
      case 'approved':
        return Colors.green;
      case 'rejected':
        return AppColors.primary;
      default:
        return AppColors.accent;
    }
  }

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 6),
      child: Row(
        children: [
          Expanded(
            child: Text(
              article.title,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: const TextStyle(fontSize: 13),
            ),
          ),
          const SizedBox(width: 8),
          Chip(
            label: Text(
              article.status,
              style: const TextStyle(fontSize: 11, color: Colors.white),
            ),
            backgroundColor: _badgeColor(article.status),
            padding: EdgeInsets.zero,
            materialTapTargetSize: MaterialTapTargetSize.shrinkWrap,
          ),
        ],
      ),
    );
  }
}
