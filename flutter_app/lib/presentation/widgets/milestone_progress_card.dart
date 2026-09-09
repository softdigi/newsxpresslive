// flutter_app/lib/presentation/widgets/milestone_progress_card.dart

import 'package:flutter/material.dart';

/// Reusable widget for displaying milestone progress.
///
/// Props:
///   title       — e.g. "7-Day Challenge"
///   rewardText  — e.g. "₹2" or "15 coins"
///   status      — 'completed' | 'in_progress' | 'locked'
///   data        — progress map from API
class MilestoneProgressCard extends StatelessWidget {
  const MilestoneProgressCard({
    super.key,
    required this.title,
    required this.rewardText,
    required this.status,
    required this.data,
  });

  final String title;
  final String rewardText;
  final String status;
  final Map<String, dynamic> data;

  // ── Colours ──────────────────────────────────────────────────────────────
  static const Color _green    = Color(0xFF4CAF50);
  static const Color _orange   = Color(0xFFFF9800);
  static const Color _grey     = Color(0xFF9E9E9E);
  static const Color _blue     = Color(0xFF1976D2);
  static const Color _cardBg   = Color(0xFF1E1E2E);
  static const Color _surface  = Color(0xFF2A2A3E);

  @override
  Widget build(BuildContext context) {
    return Container(
      margin: const EdgeInsets.only(bottom: 12),
      decoration: BoxDecoration(
        color: _cardBg,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(
          color: _borderColor(),
          width: 1.5,
        ),
      ),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            _buildHeader(),
            const SizedBox(height: 12),
            _buildBody(),
          ],
        ),
      ),
    );
  }

  // ── Header row ────────────────────────────────────────────────────────────
  Widget _buildHeader() {
    return Row(
      children: [
        _buildStatusIcon(),
        const SizedBox(width: 10),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                title,
                style: const TextStyle(
                  color: Colors.white,
                  fontSize: 15,
                  fontWeight: FontWeight.w600,
                ),
              ),
              const SizedBox(height: 2),
              Text(
                _statusLabel(),
                style: TextStyle(
                  color: _statusColor(),
                  fontSize: 12,
                ),
              ),
            ],
          ),
        ),
        _buildRewardBadge(),
      ],
    );
  }

  // ── Body content (different per status) ──────────────────────────────────
  Widget _buildBody() {
    if (status == 'completed') {
      return _buildCompletedBody();
    } else if (status == 'locked') {
      return _buildLockedBody();
    } else {
      return _buildInProgressBody();
    }
  }

  Widget _buildCompletedBody() {
    final earnedOn = data['earned_on'] as String?;
    return Row(
      children: [
        const Icon(Icons.check_circle, color: _green, size: 16),
        const SizedBox(width: 8),
        Text(
          earnedOn != null
              ? 'Completed on ${_formatDate(earnedOn)}'
              : 'Milestone completed!',
          style: const TextStyle(color: _green, fontSize: 13),
        ),
      ],
    );
  }

  Widget _buildLockedBody() {
    final unlockAfter = data['unlock_after'] as String?;
    return Row(
      children: [
        const Icon(Icons.lock, color: _grey, size: 16),
        const SizedBox(width: 8),
        Expanded(
          child: Text(
            unlockAfter ?? 'Complete previous milestone to unlock.',
            style: const TextStyle(color: _grey, fontSize: 13),
          ),
        ),
      ],
    );
  }

  Widget _buildInProgressBody() {
    final percent      = (data['percent'] as num?)?.toInt() ?? 0;
    final activeDays   = (data['active_days'] as num?)?.toInt() ?? 0;
    final reqDays      = (data['req_days'] as num?)?.toInt() ?? 0;
    final articles     = (data['articles_read'] as num?)?.toInt() ?? 0;
    final reqArticles  = (data['req_articles'] as num?)?.toInt();
    final shares       = (data['shares'] as num?)?.toInt() ?? 0;
    final reqShares    = (data['req_shares'] as num?)?.toInt();

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        // Progress bar
        ClipRRect(
          borderRadius: BorderRadius.circular(4),
          child: LinearProgressIndicator(
            value: percent / 100.0,
            backgroundColor: _surface,
            valueColor: const AlwaysStoppedAnimation<Color>(_orange),
            minHeight: 8,
          ),
        ),
        const SizedBox(height: 8),

        // Stats row
        Row(
          children: [
            _buildStat('📅', '$activeDays/$reqDays days'),
            if (reqArticles != null) ...[
              const SizedBox(width: 14),
              _buildStat('📰', '$articles/$reqArticles articles'),
            ],
            if (reqShares != null) ...[
              const SizedBox(width: 14),
              _buildStat('🔗', '$shares/$reqShares shares'),
            ],
          ],
        ),
        const SizedBox(height: 6),

        // Percent label
        Align(
          alignment: Alignment.centerRight,
          child: Text(
            '$percent%',
            style: const TextStyle(
              color: _orange,
              fontSize: 13,
              fontWeight: FontWeight.w600,
            ),
          ),
        ),

        // Estimated completion date (if available)
        if (data['estimated_date'] != null) ...[
          const SizedBox(height: 4),
          Text(
            'Est. completion: ${_formatDate(data['estimated_date'])}',
            style: const TextStyle(color: _grey, fontSize: 12),
          ),
        ],
      ],
    );
  }

  // ── Helpers ───────────────────────────────────────────────────────────────

  Widget _buildStatusIcon() {
    if (status == 'completed') {
      return Container(
        width: 36, height: 36,
        decoration: BoxDecoration(
          color: _green.withOpacity(0.15),
          shape: BoxShape.circle,
        ),
        child: const Icon(Icons.emoji_events, color: _green, size: 20),
      );
    } else if (status == 'locked') {
      return Container(
        width: 36, height: 36,
        decoration: BoxDecoration(
          color: _grey.withOpacity(0.15),
          shape: BoxShape.circle,
        ),
        child: const Icon(Icons.lock, color: _grey, size: 20),
      );
    } else {
      return Container(
        width: 36, height: 36,
        decoration: BoxDecoration(
          color: _orange.withOpacity(0.15),
          shape: BoxShape.circle,
        ),
        child: const Icon(Icons.trending_up, color: _orange, size: 20),
      );
    }
  }

  Widget _buildRewardBadge() {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
      decoration: BoxDecoration(
        color: status == 'completed'
            ? _green.withOpacity(0.15)
            : _orange.withOpacity(0.1),
        borderRadius: BorderRadius.circular(20),
        border: Border.all(
          color: status == 'completed'
              ? _green.withOpacity(0.4)
              : _orange.withOpacity(0.3),
        ),
      ),
      child: Text(
        rewardText,
        style: TextStyle(
          color: status == 'completed' ? _green : _orange,
          fontSize: 13,
          fontWeight: FontWeight.w700,
        ),
      ),
    );
  }

  Widget _buildStat(String emoji, String text) {
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Text(emoji, style: const TextStyle(fontSize: 13)),
        const SizedBox(width: 4),
        Text(text, style: const TextStyle(color: Color(0xFFB0B0B0), fontSize: 12)),
      ],
    );
  }

  Color _borderColor() {
    if (status == 'completed') return _green.withOpacity(0.4);
    if (status == 'locked')    return _grey.withOpacity(0.2);
    return _orange.withOpacity(0.3);
  }

  Color _statusColor() {
    if (status == 'completed') return _green;
    if (status == 'locked')    return _grey;
    return _orange;
  }

  String _statusLabel() {
    if (status == 'completed') return '✅ Completed';
    if (status == 'locked')    return '🔒 Locked';
    return '⏳ In Progress';
  }

  String _formatDate(String? raw) {
    if (raw == null || raw.isEmpty) return '';
    try {
      final dt = DateTime.parse(raw);
      const months = ['Jan','Feb','Mar','Apr','May','Jun',
                      'Jul','Aug','Sep','Oct','Nov','Dec'];
      return '${dt.day} ${months[dt.month - 1]} ${dt.year}';
    } catch (_) {
      return raw;
    }
  }
}
