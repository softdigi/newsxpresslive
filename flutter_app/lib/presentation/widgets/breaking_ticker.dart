import 'package:flutter/material.dart';
import '../../core/constants/app_colors.dart';
import '../../data/models/news_article.dart';

/// Horizontal scrolling breaking news ticker (animated marquee-style).
class BreakingTicker extends StatefulWidget {
  const BreakingTicker({
    super.key,
    required this.article,
    required this.onTap,
  });

  final NewsArticle article;
  final VoidCallback onTap;

  @override
  State<BreakingTicker> createState() => _BreakingTickerState();
}

class _BreakingTickerState extends State<BreakingTicker>
    with SingleTickerProviderStateMixin {
  late ScrollController _scrollCtrl;
  late AnimationController _animCtrl;

  @override
  void initState() {
    super.initState();
    _scrollCtrl = ScrollController();
    _animCtrl   = AnimationController(
      vsync: this,
      duration: const Duration(seconds: 18),
    )..addStatusListener((status) {
      if (status == AnimationStatus.completed) {
        _scrollCtrl.jumpTo(0);
        _animCtrl.forward(from: 0);
      }
    });

    // Start scrolling after first frame
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (_scrollCtrl.hasClients &&
          _scrollCtrl.position.maxScrollExtent > 0) {
        _animCtrl.forward();
        _animCtrl.addListener(_onAnimTick);
      }
    });
  }

  void _onAnimTick() {
    if (_scrollCtrl.hasClients) {
      final maxScroll = _scrollCtrl.position.maxScrollExtent;
      _scrollCtrl.jumpTo(_animCtrl.value * maxScroll);
    }
  }

  @override
  void dispose() {
    _animCtrl.removeListener(_onAnimTick);
    _animCtrl.dispose();
    _scrollCtrl.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTap: widget.onTap,
      child: Container(
        color: AppColors.breakingBadge,
        padding: const EdgeInsets.symmetric(vertical: 8),
        child: Row(
          children: [
            // BREAKING label
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 2),
              color: Colors.white,
              child: const Text(
                'BREAKING',
                style: TextStyle(
                  color: AppColors.breakingBadge,
                  fontWeight: FontWeight.w900,
                  fontSize: 11,
                  letterSpacing: 1,
                ),
              ),
            ),
            const SizedBox(width: 10),
            // Scrolling title
            Expanded(
              child: SingleChildScrollView(
                controller: _scrollCtrl,
                scrollDirection: Axis.horizontal,
                physics: const NeverScrollableScrollPhysics(),
                child: Text(
                  widget.article.title,
                  style: const TextStyle(
                    color: Colors.white,
                    fontSize: 13,
                    fontWeight: FontWeight.w500,
                  ),
                  maxLines: 1,
                ),
              ),
            ),
            const Padding(
              padding: EdgeInsets.only(right: 8),
              child: Icon(Icons.chevron_right, color: Colors.white, size: 18),
            ),
          ],
        ),
      ),
    );
  }
}
