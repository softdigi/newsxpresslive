// flutter_app/lib/presentation/screens/wallet/reward_screen.dart
//
// Reward / Wallet screen.
// Fetches data from GET /web/api/wallet/reward_status.php
// and renders different UI for INR (India) vs Coins (Global) users.

import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:http/http.dart' as http;
import '../../../core/constants/api_endpoints.dart';
import '../../widgets/milestone_progress_card.dart';

class RewardScreen extends StatefulWidget {
  /// Firebase ID token for authenticated requests.
  final String idToken;

  const RewardScreen({super.key, required this.idToken});

  @override
  State<RewardScreen> createState() => _RewardScreenState();
}

class _RewardScreenState extends State<RewardScreen>
    with SingleTickerProviderStateMixin {
  // ── State ──────────────────────────────────────────────────────────────────
  Map<String, dynamic>? _data;
  bool _loading  = true;
  String? _error;

  // ── Colours ────────────────────────────────────────────────────────────────
  static const Color _bg      = Color(0xFF12121F);
  static const Color _card    = Color(0xFF1E1E2E);
  static const Color _surface = Color(0xFF2A2A3E);
  static const Color _primary = Color(0xFFE50914);
  static const Color _green   = Color(0xFF4CAF50);
  static const Color _orange  = Color(0xFFFF9800);
  static const Color _blue    = Color(0xFF4A90D9);
  static const Color _textPri = Color(0xFFE2E2E2);
  static const Color _textSec = Color(0xFF9E9EA0);

  @override
  void initState() {
    super.initState();
    _loadData();
  }

  // ─────────────────────────────────────────────────────────────────────────
  Future<void> _loadData() async {
    setState(() { _loading = true; _error = null; });
    try {
      final uri = Uri.parse('${ApiEndpoints.baseUrl}/api/wallet/reward_status.php');
      final res = await http.get(uri, headers: {
        'Authorization': 'Bearer ${widget.idToken}',
      }).timeout(const Duration(seconds: 15));

      if (res.statusCode == 200) {
        final decoded = json.decode(res.body);
        if (decoded['success'] == true) {
          setState(() { _data = decoded; _loading = false; });
          return;
        }
        throw Exception(decoded['error'] ?? 'Failed to load wallet data');
      } else {
        throw Exception('HTTP ${res.statusCode}');
      }
    } catch (e) {
      setState(() { _error = e.toString(); _loading = false; });
    }
  }

  // ─────────────────────────────────────────────────────────────────────────
  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: _bg,
      appBar: AppBar(
        backgroundColor: _card,
        foregroundColor: _textPri,
        title: const Text(
          '💰 My Rewards',
          style: TextStyle(fontWeight: FontWeight.bold),
        ),
        actions: [
          IconButton(
            icon: const Icon(Icons.refresh),
            tooltip: 'Refresh',
            onPressed: _loadData,
          ),
        ],
      ),
      body: _buildBody(),
    );
  }

  Widget _buildBody() {
    if (_loading) {
      return const Center(child: CircularProgressIndicator(color: _primary));
    }
    if (_error != null) {
      return Center(
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            const Icon(Icons.error_outline, color: _textSec, size: 48),
            const SizedBox(height: 12),
            Text(_error!, style: const TextStyle(color: _textSec, fontSize: 14), textAlign: TextAlign.center),
            const SizedBox(height: 16),
            ElevatedButton(onPressed: _loadData, child: const Text('Retry')),
          ],
        ),
      );
    }
    if (_data == null) return const SizedBox.shrink();

    final walletType = _data!['wallet_type'] as String? ?? 'coins';
    return walletType == 'inr' ? _buildInrUI() : _buildCoinsUI();
  }

  // =========================================================================
  // INR (India) UI
  // =========================================================================
  Widget _buildInrUI() {
    final balance     = (_data!['balance'] as num?)?.toDouble() ?? 0.0;
    final totalEarned = (_data!['total_earned'] as num?)?.toDouble() ?? 0.0;
    final milestones  = _data!['milestones'] as Map<String, dynamic>? ?? {};
    final articleRew  = _data!['article_rewards'] as Map<String, dynamic>? ?? {};
    final referral    = _data!['referral'] as Map<String, dynamic>? ?? {};
    final withdrawal  = _data!['withdrawal'] as Map<String, dynamic>? ?? {};
    final monthlySummary = _data!['this_month_summary'] as Map<String, dynamic>? ?? {};
    final recentTx    = (_data!['recent_transactions'] as List<dynamic>?) ?? [];

    return RefreshIndicator(
      onRefresh: _loadData,
      color: _primary,
      child: SingleChildScrollView(
        physics: const AlwaysScrollableScrollPhysics(),
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            _buildInrWalletCard(balance, totalEarned, withdrawal),
            const SizedBox(height: 20),
            _buildMilestoneSection(milestones, 'inr'),
            const SizedBox(height: 20),
            _buildArticleRewardSection(articleRew),
            const SizedBox(height: 20),
            _buildReferralSection(referral, 'inr'),
            const SizedBox(height: 20),
            _buildMonthlySummary(monthlySummary),
            const SizedBox(height: 20),
            _buildRecentTransactions(recentTx),
            const SizedBox(height: 20),
          ],
        ),
      ),
    );
  }

  // ── INR Wallet Card ───────────────────────────────────────────────────────
  Widget _buildInrWalletCard(
    double balance,
    double totalEarned,
    Map<String, dynamic> withdrawal,
  ) {
    final eligible     = withdrawal['eligible'] as bool? ?? false;
    final minAmount    = (withdrawal['min_amount'] as num?)?.toDouble() ?? 100.0;
    final daysLeft     = (withdrawal['days_until_eligible'] as num?)?.toInt() ?? 0;
    final canWithdraw  = eligible && balance >= minAmount;

    return Container(
      width: double.infinity,
      decoration: BoxDecoration(
        gradient: const LinearGradient(
          colors: [Color(0xFF1E3A5F), Color(0xFF0D1B2A)],
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
        ),
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: _blue.withOpacity(0.3)),
      ),
      padding: const EdgeInsets.all(20),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              const Text('💳', style: TextStyle(fontSize: 20)),
              const SizedBox(width: 8),
              const Text(
                'INR Wallet',
                style: TextStyle(color: _textSec, fontSize: 14),
              ),
              const Spacer(),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                decoration: BoxDecoration(
                  color: _green.withOpacity(0.15),
                  borderRadius: BorderRadius.circular(12),
                  border: Border.all(color: _green.withOpacity(0.4)),
                ),
                child: const Text(
                  '🇮🇳 India',
                  style: TextStyle(color: _green, fontSize: 12, fontWeight: FontWeight.w600),
                ),
              ),
            ],
          ),
          const SizedBox(height: 12),
          Text(
            '₹${balance.toStringAsFixed(2)}',
            style: const TextStyle(
              color: Colors.white,
              fontSize: 38,
              fontWeight: FontWeight.w800,
              letterSpacing: -1,
            ),
          ),
          const SizedBox(height: 4),
          Text(
            'Total Earned: ₹${totalEarned.toStringAsFixed(2)}',
            style: const TextStyle(color: _textSec, fontSize: 13),
          ),
          const SizedBox(height: 16),
          SizedBox(
            width: double.infinity,
            child: ElevatedButton.icon(
              onPressed: canWithdraw ? _openWithdrawDialog : null,
              style: ElevatedButton.styleFrom(
                backgroundColor: canWithdraw ? _green : const Color(0xFF2A2A3E),
                foregroundColor: Colors.white,
                padding: const EdgeInsets.symmetric(vertical: 13),
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                disabledBackgroundColor: const Color(0xFF2A2A3E),
                disabledForegroundColor: _textSec,
              ),
              icon: Icon(canWithdraw ? Icons.account_balance_wallet : Icons.lock_clock),
              label: Text(
                canWithdraw
                    ? 'Withdraw ₹'
                    : (daysLeft > 0
                        ? 'Eligible in $daysLeft days'
                        : 'Min ₹${minAmount.toStringAsFixed(0)} required'),
                style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 15),
              ),
            ),
          ),
        ],
      ),
    );
  }

  void _openWithdrawDialog() {
    ScaffoldMessenger.of(context).showSnackBar(
      const SnackBar(content: Text('Withdrawal feature coming soon!')),
    );
  }

  // =========================================================================
  // Coins (Global) UI
  // =========================================================================
  Widget _buildCoinsUI() {
    final balance      = (_data!['balance'] as num?)?.toInt() ?? 0;
    final totalEarned  = (_data!['total_earned'] as num?)?.toInt() ?? 0;
    final coinsDisplay = _data!['coins_value_display'] as String? ?? '';
    final milestones   = _data!['milestones'] as Map<String, dynamic>? ?? {};
    final redemptions  = (_data!['redemption_options'] as List<dynamic>?) ?? [];
    final referral     = _data!['referral'] as Map<String, dynamic>? ?? {};
    final recentTx     = (_data!['recent_transactions'] as List<dynamic>?) ?? [];

    return RefreshIndicator(
      onRefresh: _loadData,
      color: _primary,
      child: SingleChildScrollView(
        physics: const AlwaysScrollableScrollPhysics(),
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            _buildCoinsWalletCard(balance, totalEarned, coinsDisplay),
            const SizedBox(height: 20),
            _buildMilestoneSection(milestones, 'coins'),
            const SizedBox(height: 20),
            _buildRedemptionSection(redemptions),
            const SizedBox(height: 20),
            _buildReferralSection(referral, 'coins'),
            const SizedBox(height: 20),
            _buildRecentTransactions(recentTx),
            const SizedBox(height: 20),
          ],
        ),
      ),
    );
  }

  // ── Coins Wallet Card ─────────────────────────────────────────────────────
  Widget _buildCoinsWalletCard(int balance, int totalEarned, String coinsDisplay) {
    return Container(
      width: double.infinity,
      decoration: BoxDecoration(
        gradient: const LinearGradient(
          colors: [Color(0xFF3D2200), Color(0xFF1A0F00)],
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
        ),
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: _orange.withOpacity(0.3)),
      ),
      padding: const EdgeInsets.all(20),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              const Text('🪙', style: TextStyle(fontSize: 20)),
              const SizedBox(width: 8),
              const Text('Coins Wallet', style: TextStyle(color: _textSec, fontSize: 14)),
              const Spacer(),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                decoration: BoxDecoration(
                  color: _blue.withOpacity(0.15),
                  borderRadius: BorderRadius.circular(12),
                  border: Border.all(color: _blue.withOpacity(0.4)),
                ),
                child: const Text(
                  '🌍 Global',
                  style: TextStyle(color: _blue, fontSize: 12, fontWeight: FontWeight.w600),
                ),
              ),
            ],
          ),
          const SizedBox(height: 12),
          Row(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              Text(
                '$balance',
                style: const TextStyle(
                  color: _orange,
                  fontSize: 42,
                  fontWeight: FontWeight.w800,
                  letterSpacing: -1,
                ),
              ),
              const Padding(
                padding: EdgeInsets.only(left: 6, bottom: 8),
                child: Text('Coins', style: TextStyle(color: _textSec, fontSize: 16)),
              ),
            ],
          ),
          if (coinsDisplay.isNotEmpty)
            Text('≈ $coinsDisplay', style: const TextStyle(color: _orange, fontSize: 13)),
          const SizedBox(height: 4),
          Text(
            'Total Earned: $totalEarned coins',
            style: const TextStyle(color: _textSec, fontSize: 13),
          ),
        ],
      ),
    );
  }

  // ─────────────────────────────────────────────────────────────────────────
  // Milestone Section (shared for INR & Coins)
  // ─────────────────────────────────────────────────────────────────────────
  Widget _buildMilestoneSection(Map<String, dynamic> milestones, String walletType) {
    final config = _data!;
    final reward7  = walletType == 'inr'
        ? '₹${(config['milestones']?['7_day']?['reward'] as num?)?.toStringAsFixed(2) ?? '2.00'}'
        : '${(config['milestones']?['7_day']?['reward'] as num?)?.toInt() ?? 15} coins';
    final reward30 = walletType == 'inr'
        ? '₹${(config['milestones']?['30_day']?['reward'] as num?)?.toStringAsFixed(2) ?? '5.00'}'
        : '${(config['milestones']?['30_day']?['reward'] as num?)?.toInt() ?? 35} coins';
    final reward90 = walletType == 'inr'
        ? '₹${(config['milestones']?['90_day']?['reward'] as num?)?.toStringAsFixed(2) ?? '15.00'}'
        : '${(config['milestones']?['90_day']?['reward'] as num?)?.toInt() ?? 100} coins';

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const Text(
          'Aapki kamai ka safar 🚀',
          style: TextStyle(color: Colors.white, fontSize: 17, fontWeight: FontWeight.w700),
        ),
        const SizedBox(height: 12),
        MilestoneProgressCard(
          title: '7-Day Challenge',
          rewardText: reward7,
          status: (milestones['7_day']?['status'] as String?) ?? 'in_progress',
          data: (milestones['7_day'] as Map<String, dynamic>?) ?? {},
        ),
        MilestoneProgressCard(
          title: '30-Day Streak',
          rewardText: reward30,
          status: (milestones['30_day']?['status'] as String?) ?? 'locked',
          data: (milestones['30_day'] as Map<String, dynamic>?) ?? {},
        ),
        MilestoneProgressCard(
          title: '90-Day Champion',
          rewardText: reward90,
          status: (milestones['90_day']?['status'] as String?) ?? 'locked',
          data: (milestones['90_day'] as Map<String, dynamic>?) ?? {},
        ),
      ],
    );
  }

  // ─────────────────────────────────────────────────────────────────────────
  // Article Rewards Section (INR only)
  // ─────────────────────────────────────────────────────────────────────────
  Widget _buildArticleRewardSection(Map<String, dynamic> articleRew) {
    final enabled       = articleRew['enabled'] as bool? ?? false;
    final perArticle    = (articleRew['per_article'] as num?)?.toDouble() ?? 5.0;
    final thisMonth     = (articleRew['this_month_earned'] as num?)?.toDouble() ?? 0.0;
    final monthlyCap    = (articleRew['monthly_cap'] as num?)?.toDouble() ?? 50.0;
    final remaining     = (articleRew['remaining_cap'] as num?)?.toDouble() ?? 0.0;
    final articlesCount = (articleRew['articles_this_month'] as num?)?.toInt() ?? 0;
    final maxArticles   = (articleRew['max_this_month'] as num?)?.toInt() ?? 10;

    if (!enabled) {
      return Container(
        padding: const EdgeInsets.all(16),
        decoration: BoxDecoration(
          color: _card,
          borderRadius: BorderRadius.circular(14),
          border: Border.all(color: Colors.white.withOpacity(0.08)),
        ),
        child: const Row(
          children: [
            Icon(Icons.article_outlined, color: _textSec),
            SizedBox(width: 10),
            Text('Article rewards are currently disabled.', style: TextStyle(color: _textSec, fontSize: 13)),
          ],
        ),
      );
    }

    final pct = monthlyCap > 0 ? (thisMonth / monthlyCap) : 0.0;

    return Container(
      decoration: BoxDecoration(
        color: _card,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: _blue.withOpacity(0.2)),
      ),
      padding: const EdgeInsets.all(16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Row(
            children: [
              Text('📝', style: TextStyle(fontSize: 20)),
              SizedBox(width: 8),
              Text(
                'Is mahine article rewards',
                style: TextStyle(color: Colors.white, fontSize: 15, fontWeight: FontWeight.w600),
              ),
            ],
          ),
          const SizedBox(height: 14),

          // Progress bar
          ClipRRect(
            borderRadius: BorderRadius.circular(4),
            child: LinearProgressIndicator(
              value: pct.clamp(0.0, 1.0),
              backgroundColor: _surface,
              valueColor: const AlwaysStoppedAnimation<Color>(_blue),
              minHeight: 8,
            ),
          ),
          const SizedBox(height: 8),

          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Text(
                '₹${thisMonth.toStringAsFixed(2)} / ₹${monthlyCap.toStringAsFixed(0)}',
                style: const TextStyle(color: _textSec, fontSize: 13),
              ),
              Text(
                '₹${remaining.toStringAsFixed(2)} remaining',
                style: const TextStyle(color: _green, fontSize: 13, fontWeight: FontWeight.w600),
              ),
            ],
          ),
          const SizedBox(height: 10),

          Row(
            children: [
              _buildChip('📰 $articlesCount articles approved'),
              const SizedBox(width: 8),
              _buildChip('${maxArticles - articlesCount} aur ho sakte hain'),
            ],
          ),
          const SizedBox(height: 8),
          Text(
            'Har article approve hone par: ₹${perArticle.toStringAsFixed(2)}',
            style: const TextStyle(color: _textSec, fontSize: 12),
          ),
        ],
      ),
    );
  }

  // ─────────────────────────────────────────────────────────────────────────
  // Referral Section
  // ─────────────────────────────────────────────────────────────────────────
  Widget _buildReferralSection(Map<String, dynamic> referral, String walletType) {
    final code          = referral['code'] as String? ?? '';
    final shareUrl      = referral['share_url'] as String? ?? '';
    final bonusPer      = referral['your_bonus_per_referral'] as String? ?? '';
    final when          = referral['when'] as String? ?? '';
    final lifetimeBonus = referral['lifetime_bonus'] as String? ?? '';
    final totalRefs     = (referral['total_referrals'] as num?)?.toInt() ?? 0;
    final activeRefs    = (referral['active_referrals'] as num?)?.toInt() ?? 0;
    final totalEarned   = (referral['total_referral_earned'] as num?)?.toDouble() ?? 0.0;

    return Container(
      decoration: BoxDecoration(
        color: _card,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: _orange.withOpacity(0.2)),
      ),
      padding: const EdgeInsets.all(16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Row(
            children: [
              Text('👥', style: TextStyle(fontSize: 20)),
              SizedBox(width: 8),
              Text(
                'Referral Program',
                style: TextStyle(color: Colors.white, fontSize: 15, fontWeight: FontWeight.w600),
              ),
            ],
          ),
          const SizedBox(height: 14),

          // Code display
          if (code.isNotEmpty) ...[
            const Text('Aapka referral code', style: TextStyle(color: _textSec, fontSize: 12)),
            const SizedBox(height: 6),
            GestureDetector(
              onTap: () => _copyToClipboard(code),
              child: Container(
                padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
                decoration: BoxDecoration(
                  color: _surface,
                  borderRadius: BorderRadius.circular(10),
                  border: Border.all(color: _orange.withOpacity(0.4)),
                ),
                child: Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    Text(
                      code,
                      style: const TextStyle(
                        color: _orange,
                        fontSize: 22,
                        fontWeight: FontWeight.w800,
                        letterSpacing: 3,
                        fontFamily: 'monospace',
                      ),
                    ),
                    const Icon(Icons.copy, color: _orange, size: 20),
                  ],
                ),
              ),
            ),
            const SizedBox(height: 12),
          ],

          // What they get
          if (bonusPer.isNotEmpty || when.isNotEmpty) ...[
            Container(
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(
                color: _surface,
                borderRadius: BorderRadius.circular(10),
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  if (bonusPer.isNotEmpty)
                    _buildBullet('💰 Aapko milega: $bonusPer $when'),
                  if (lifetimeBonus.isNotEmpty && walletType == 'inr')
                    _buildBullet('♾️ Lifetime share: $lifetimeBonus'),
                  _buildBullet('🎁 Dost ko milega: milestones ka reward bhi'),
                ],
              ),
            ),
            const SizedBox(height: 12),
          ],

          // Share buttons
          Row(
            children: [
              Expanded(
                child: ElevatedButton.icon(
                  onPressed: () => _shareOnWhatsApp(code, shareUrl),
                  style: ElevatedButton.styleFrom(
                    backgroundColor: const Color(0xFF25D366),
                    foregroundColor: Colors.white,
                    padding: const EdgeInsets.symmetric(vertical: 11),
                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
                  ),
                  icon: const Text('📲', style: TextStyle(fontSize: 16)),
                  label: const Text('WhatsApp', style: TextStyle(fontSize: 13)),
                ),
              ),
              const SizedBox(width: 8),
              Expanded(
                child: OutlinedButton.icon(
                  onPressed: () => _copyToClipboard(shareUrl.isNotEmpty ? shareUrl : code),
                  style: OutlinedButton.styleFrom(
                    foregroundColor: _textPri,
                    side: const BorderSide(color: Color(0xFF3A3A4E)),
                    padding: const EdgeInsets.symmetric(vertical: 11),
                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
                  ),
                  icon: const Icon(Icons.link, size: 16),
                  label: const Text('Copy Link', style: TextStyle(fontSize: 13)),
                ),
              ),
            ],
          ),
          const SizedBox(height: 14),

          // Stats row
          Container(
            padding: const EdgeInsets.symmetric(vertical: 10, horizontal: 14),
            decoration: BoxDecoration(
              color: _surface,
              borderRadius: BorderRadius.circular(10),
            ),
            child: Row(
              mainAxisAlignment: MainAxisAlignment.spaceAround,
              children: [
                _buildReferralStat('Total', '$totalRefs'),
                _buildDivider(),
                _buildReferralStat('Active', '$activeRefs'),
                _buildDivider(),
                _buildReferralStat('Earned', walletType == 'inr'
                    ? '₹${totalEarned.toStringAsFixed(2)}'
                    : '${totalEarned.toInt()} coins'),
              ],
            ),
          ),
        ],
      ),
    );
  }

  // ─────────────────────────────────────────────────────────────────────────
  // Monthly Summary (INR only)
  // ─────────────────────────────────────────────────────────────────────────
  Widget _buildMonthlySummary(Map<String, dynamic> summary) {
    final totalEarned = (summary['total_earned'] as num?)?.toDouble() ?? 0.0;
    final monthlyCap  = (summary['monthly_cap'] as num?)?.toDouble() ?? 0.0;
    final remaining   = (summary['remaining_cap'] as num?)?.toDouble() ?? 0.0;
    final breakdown   = summary['breakdown'] as Map<String, dynamic>? ?? {};

    return Container(
      decoration: BoxDecoration(
        color: _card,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: Colors.white.withOpacity(0.08)),
      ),
      padding: const EdgeInsets.all(16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Text(
            'Is Mahine ka Summary 📅',
            style: TextStyle(color: Colors.white, fontSize: 15, fontWeight: FontWeight.w600),
          ),
          const SizedBox(height: 14),
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              _buildSummaryItem('Total Earned', '₹${totalEarned.toStringAsFixed(2)}', _green),
              _buildSummaryItem('Monthly Cap', '₹${monthlyCap.toStringAsFixed(0)}', _textSec),
              _buildSummaryItem('Remaining', '₹${remaining.toStringAsFixed(2)}', _orange),
            ],
          ),
          if (breakdown.isNotEmpty) ...[
            const SizedBox(height: 12),
            const Divider(color: Color(0xFF2A2A3E)),
            const SizedBox(height: 8),
            Wrap(
              spacing: 10,
              runSpacing: 8,
              children: [
                if (breakdown['milestones'] != null)
                  _buildChip('🏆 Milestones: ₹${(breakdown['milestones'] as num).toStringAsFixed(2)}'),
                if (breakdown['articles'] != null)
                  _buildChip('📝 Articles: ₹${(breakdown['articles'] as num).toStringAsFixed(2)}'),
                if (breakdown['referrals'] != null)
                  _buildChip('👥 Referrals: ₹${(breakdown['referrals'] as num).toStringAsFixed(2)}'),
              ],
            ),
          ],
        ],
      ),
    );
  }

  // ─────────────────────────────────────────────────────────────────────────
  // Redemption Section (Coins only)
  // ─────────────────────────────────────────────────────────────────────────
  Widget _buildRedemptionSection(List<dynamic> options) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const Text(
          'Coins use karo, features unlock karo 🎮',
          style: TextStyle(color: Colors.white, fontSize: 17, fontWeight: FontWeight.w700),
        ),
        const SizedBox(height: 12),
        GridView.builder(
          shrinkWrap: true,
          physics: const NeverScrollableScrollPhysics(),
          gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
            crossAxisCount: 2,
            childAspectRatio: 1.1,
            crossAxisSpacing: 10,
            mainAxisSpacing: 10,
          ),
          itemCount: options.length,
          itemBuilder: (context, i) {
            final opt       = options[i] as Map<String, dynamic>;
            final canAfford = opt['can_afford'] as bool? ?? false;
            final costCoins = (opt['cost_coins'] as num?)?.toInt() ?? 0;
            final needMore  = (opt['need_more'] as num?)?.toInt() ?? 0;
            final typeName  = opt['type'] as String? ?? '';
            final desc      = opt['description'] as String? ?? '';

            return Container(
              decoration: BoxDecoration(
                color: _card,
                borderRadius: BorderRadius.circular(14),
                border: Border.all(
                  color: canAfford ? _green.withOpacity(0.3) : Colors.white.withOpacity(0.08),
                ),
              ),
              padding: const EdgeInsets.all(12),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(_redemptionEmoji(typeName), style: const TextStyle(fontSize: 22)),
                  const SizedBox(height: 6),
                  Text(
                    _redemptionTitle(typeName),
                    style: const TextStyle(color: _textPri, fontSize: 13, fontWeight: FontWeight.w600),
                    maxLines: 1, overflow: TextOverflow.ellipsis,
                  ),
                  const SizedBox(height: 2),
                  Text(desc, style: const TextStyle(color: _textSec, fontSize: 11), maxLines: 2),
                  const Spacer(),
                  SizedBox(
                    width: double.infinity,
                    child: ElevatedButton(
                      onPressed: canAfford ? () => _redeemOption(opt) : null,
                      style: ElevatedButton.styleFrom(
                        backgroundColor: canAfford ? _green : _surface,
                        foregroundColor: Colors.white,
                        padding: const EdgeInsets.symmetric(vertical: 7),
                        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
                        disabledBackgroundColor: _surface,
                        disabledForegroundColor: _textSec,
                        textStyle: const TextStyle(fontSize: 12, fontWeight: FontWeight.w600),
                      ),
                      child: Text(
                        canAfford
                            ? '🪙 $costCoins coins'
                            : '${needMore > 0 ? "Need $needMore more" : "$costCoins coins"}',
                      ),
                    ),
                  ),
                ],
              ),
            );
          },
        ),
      ],
    );
  }

  void _redeemOption(Map<String, dynamic> opt) {
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(content: Text('Redeeming: ${opt['type']} — coming soon!')),
    );
  }

  // ─────────────────────────────────────────────────────────────────────────
  // Recent Transactions
  // ─────────────────────────────────────────────────────────────────────────
  Widget _buildRecentTransactions(List<dynamic> transactions) {
    if (transactions.isEmpty) return const SizedBox.shrink();

    return Container(
      decoration: BoxDecoration(
        color: _card,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: Colors.white.withOpacity(0.08)),
      ),
      padding: const EdgeInsets.all(16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Text(
            'Recent Activity',
            style: TextStyle(color: Colors.white, fontSize: 15, fontWeight: FontWeight.w600),
          ),
          const SizedBox(height: 10),
          ...transactions.take(10).map((tx) {
            final txMap  = tx as Map<String, dynamic>;
            final type   = txMap['transaction_type'] as String? ?? '';
            final amount = (txMap['amount'] as num?)?.toDouble() ?? 0.0;
            final wallet = txMap['wallet_type'] as String? ?? 'inr';
            final time   = txMap['created_at'] as String? ?? '';

            return Padding(
              padding: const EdgeInsets.symmetric(vertical: 6),
              child: Row(
                children: [
                  Text(_txEmoji(type), style: const TextStyle(fontSize: 20)),
                  const SizedBox(width: 10),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          _txLabel(type),
                          style: const TextStyle(color: _textPri, fontSize: 13),
                        ),
                        if (time.isNotEmpty)
                          Text(
                            _formatTime(time),
                            style: const TextStyle(color: _textSec, fontSize: 11),
                          ),
                      ],
                    ),
                  ),
                  Text(
                    wallet == 'inr'
                        ? '+₹${amount.toStringAsFixed(2)}'
                        : '+${amount.toInt()} coins',
                    style: const TextStyle(
                      color: _green,
                      fontSize: 14,
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                ],
              ),
            );
          }),
        ],
      ),
    );
  }

  // ─────────────────────────────────────────────────────────────────────────
  // Helper widgets & functions
  // ─────────────────────────────────────────────────────────────────────────

  Widget _buildChip(String text) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
      decoration: BoxDecoration(
        color: _surface,
        borderRadius: BorderRadius.circular(20),
      ),
      child: Text(text, style: const TextStyle(color: _textSec, fontSize: 12)),
    );
  }

  Widget _buildBullet(String text) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 6),
      child: Text(text, style: const TextStyle(color: _textPri, fontSize: 13)),
    );
  }

  Widget _buildDivider() {
    return Container(width: 1, height: 30, color: const Color(0xFF3A3A4E));
  }

  Widget _buildReferralStat(String label, String value) {
    return Column(
      children: [
        Text(value, style: const TextStyle(color: Colors.white, fontSize: 16, fontWeight: FontWeight.w700)),
        const SizedBox(height: 2),
        Text(label, style: const TextStyle(color: _textSec, fontSize: 11)),
      ],
    );
  }

  Widget _buildSummaryItem(String label, String value, Color color) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(label, style: const TextStyle(color: _textSec, fontSize: 11)),
        const SizedBox(height: 3),
        Text(value, style: TextStyle(color: color, fontSize: 15, fontWeight: FontWeight.w700)),
      ],
    );
  }

  void _copyToClipboard(String text) {
    Clipboard.setData(ClipboardData(text: text));
    ScaffoldMessenger.of(context).showSnackBar(
      const SnackBar(
        content: Text('Copied to clipboard!'),
        duration: Duration(seconds: 2),
        backgroundColor: Color(0xFF4CAF50),
      ),
    );
  }

  void _shareOnWhatsApp(String code, String shareUrl) {
    final msg = 'NewsXpressLive join karo! Mera referral code: $code\n'
        'App download karo aur daily rewards kamao 📰💰\n${shareUrl.isNotEmpty ? shareUrl : "https://newsxpresslive.com"}';
    _copyToClipboard(msg);
    ScaffoldMessenger.of(context).showSnackBar(
      const SnackBar(content: Text('Message copied — paste in WhatsApp!')),
    );
  }

  String _txEmoji(String type) {
    return switch (type) {
      'milestone_7day'  || 'milestone_30day' || 'milestone_90day' => '🏆',
      'article_reward'  => '📝',
      'referral_bonus'  => '👥',
      'lifetime_share'  => '♾️',
      'withdrawal'      => '💳',
      _                 => '✅',
    };
  }

  String _txLabel(String type) {
    return switch (type) {
      'milestone_7day'   => 'Milestone 7-Day',
      'milestone_30day'  => 'Milestone 30-Day',
      'milestone_90day'  => 'Milestone 90-Day',
      'article_reward'   => 'Article Approved',
      'referral_bonus'   => 'Referral Bonus',
      'lifetime_share'   => 'Lifetime Share',
      'withdrawal'       => 'Withdrawal',
      _                  => type.replaceAll('_', ' ').toUpperCase(),
    };
  }

  String _redemptionEmoji(String type) {
    return switch (type) {
      'featured_listing'  => '📌',
      'premium_article'   => '⭐',
      'blue_tick_discount'=> '✔️',
      'leaderboard_boost' => '🚀',
      _                   => '🎁',
    };
  }

  String _redemptionTitle(String type) {
    return switch (type) {
      'featured_listing'  => 'Featured Listing',
      'premium_article'   => 'Premium Article',
      'blue_tick_discount'=> 'Blue Tick Discount',
      'leaderboard_boost' => 'Leaderboard Boost',
      _                   => type.replaceAll('_', ' '),
    };
  }

  String _formatTime(String raw) {
    try {
      final dt = DateTime.parse(raw).toLocal();
      const months = ['Jan','Feb','Mar','Apr','May','Jun',
                      'Jul','Aug','Sep','Oct','Nov','Dec'];
      return '${dt.day} ${months[dt.month - 1]}, ${dt.hour.toString().padLeft(2,'0')}:${dt.minute.toString().padLeft(2,'0')}';
    } catch (_) {
      return raw;
    }
  }
}
