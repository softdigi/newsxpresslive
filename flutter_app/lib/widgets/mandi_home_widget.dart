import 'package:flutter/material.dart';
import '../data/models/mandi_model.dart';
import '../data/services/mandi_service.dart';
import '../core/constants/app_colors.dart';
import '../presentation/screens/mandi/mandi_home_screen.dart';

/// Mini mandi widget for HomeScreen — shows top 3 commodity rates
/// from the user's favourite mandi.
class MandiHomeWidget extends StatefulWidget {
  final int? mandiId;
  const MandiHomeWidget({super.key, this.mandiId});

  @override
  State<MandiHomeWidget> createState() => _MandiHomeWidgetState();
}

class _MandiHomeWidgetState extends State<MandiHomeWidget> {
  final _svc = MandiService();
  MandiRatesResponse? _data;
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    if (widget.mandiId == null) {
      setState(() => _loading = false);
      return;
    }
    final res = await _svc.getRates(mandiId: widget.mandiId);
    if (mounted) setState(() {
      _data    = res;
      _loading = false;
    });
  }

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final cardColor = isDark ? AppColors.cardDark : AppColors.cardLight;

    return Container(
      margin: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
      decoration: BoxDecoration(
        color: cardColor,
        borderRadius: BorderRadius.circular(12),
        boxShadow: [BoxShadow(color: Colors.black.withAlpha(15), blurRadius: 6, offset: const Offset(0,2))],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          // Header
          Padding(
            padding: const EdgeInsets.fromLTRB(12, 12, 12, 0),
            child: Row(
              children: [
                const Text('🌾', style: TextStyle(fontSize: 16)),
                const SizedBox(width: 6),
                Expanded(
                  child: Text(
                    _data?.mandi.nameHi ?? 'मंडी भाव',
                    style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14),
                    overflow: TextOverflow.ellipsis,
                  ),
                ),
                Text(
                  _data?.date ?? '',
                  style: TextStyle(
                    fontSize: 11,
                    color: isDark ? AppColors.textSecondaryDark : AppColors.textSecondaryLight,
                  ),
                ),
              ],
            ),
          ),
          const Divider(height: 12, indent: 12, endIndent: 12),
          if (_loading)
            const Padding(
              padding: EdgeInsets.all(16),
              child: Center(child: CircularProgressIndicator(strokeWidth: 2)),
            )
          else if (_data == null || _data!.rates.isEmpty)
            const Padding(
              padding: EdgeInsets.all(12),
              child: Text('भाव उपलब्ध नहीं', style: TextStyle(color: Colors.grey)),
            )
          else
            ..._data!.rates.take(3).map((r) => _miniRateRow(r, isDark)),
          // Footer
          InkWell(
            onTap: () => Navigator.push(
              context,
              MaterialPageRoute(builder: (_) => const MandiHomeScreen()),
            ),
            child: Container(
              width: double.infinity,
              padding: const EdgeInsets.symmetric(vertical: 10),
              decoration: BoxDecoration(
                color: AppColors.primary.withAlpha(15),
                borderRadius: const BorderRadius.vertical(bottom: Radius.circular(12)),
              ),
              child: const Center(
                child: Text(
                  'पूरा भाव देखें →',
                  style: TextStyle(color: AppColors.primary, fontWeight: FontWeight.w600, fontSize: 13),
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _miniRateRow(MandiRate r, bool isDark) {
    final isUp   = r.trend == 'up';
    final isDown = r.trend == 'down';
    final trendColor = isUp ? Colors.green : isDown ? Colors.red : Colors.grey;

    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 5),
      child: Row(
        children: [
          Expanded(
            child: Text(r.commodity,
                style: const TextStyle(fontSize: 13),
                overflow: TextOverflow.ellipsis),
          ),
          Text(
            '₹${r.modalPrice.toStringAsFixed(0)}',
            style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14),
          ),
          const SizedBox(width: 6),
          Icon(
            isUp ? Icons.arrow_upward_rounded
                : isDown ? Icons.arrow_downward_rounded
                : Icons.remove_rounded,
            size: 14,
            color: trendColor,
          ),
        ],
      ),
    );
  }
}
