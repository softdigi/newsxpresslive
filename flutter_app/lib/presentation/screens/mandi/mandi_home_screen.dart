import 'package:flutter/material.dart';
import 'package:shared_preferences/shared_preferences.dart';
import '../../data/models/mandi_model.dart';
import '../../data/services/mandi_service.dart';
import '../../core/constants/app_colors.dart';
import 'commodity_detail_screen.dart';
import 'mandi_select_screen.dart';
import 'my_alerts_screen.dart';

/// Main Mandi Bhav screen — today's commodity rates with category tabs.
class MandiHomeScreen extends StatefulWidget {
  const MandiHomeScreen({super.key});

  @override
  State<MandiHomeScreen> createState() => _MandiHomeScreenState();
}

class _MandiHomeScreenState extends State<MandiHomeScreen>
    with SingleTickerProviderStateMixin {
  static const _prefKeyMandiId   = 'mandi_fav_id';
  static const _prefKeyMandiName = 'mandi_fav_name';

  final _svc = MandiService();

  int?   _mandiId;
  String _mandiName    = 'अपनी मंडी चुनें';
  double? _distanceKm;

  MandiRatesResponse? _data;
  bool   _loading      = true;
  String _category     = 'all';

  static const _categories = [
    ('all',        'सभी'),
    ('grain',      'अनाज'),
    ('vegetable',  'सब्जी'),
    ('fruit',      'फल'),
    ('spice',      'मसाले'),
    ('oilseed',    'तिलहन'),
    ('other',      'अन्य'),
  ];

  late final TabController _tabCtrl;

  @override
  void initState() {
    super.initState();
    _tabCtrl = TabController(length: _categories.length, vsync: this);
    _tabCtrl.addListener(() {
      if (!_tabCtrl.indexIsChanging) {
        setState(() => _category = _categories[_tabCtrl.index].$1);
      }
    });
    _loadSavedMandi();
  }

  @override
  void dispose() {
    _tabCtrl.dispose();
    super.dispose();
  }

  Future<void> _loadSavedMandi() async {
    final prefs = await SharedPreferences.getInstance();
    final id   = prefs.getInt(_prefKeyMandiId);
    final name = prefs.getString(_prefKeyMandiName);
    if (id != null) {
      setState(() {
        _mandiId   = id;
        _mandiName = name ?? 'मंडी';
      });
    }
    await _fetchRates();
  }

  Future<void> _fetchRates() async {
    if (_mandiId == null) {
      setState(() => _loading = false);
      return;
    }
    setState(() => _loading = true);
    final res = await _svc.getRates(mandiId: _mandiId);
    if (mounted) {
      setState(() {
        _data    = res;
        _loading = false;
        if (res != null) {
          _mandiName  = res.mandi.nameHi;
          _distanceKm = res.mandi.distanceKm;
        }
      });
    }
  }

  Future<void> _selectMandi() async {
    final result = await Navigator.push<Map<String, dynamic>>(
      context,
      MaterialPageRoute(builder: (_) => const MandiSelectScreen()),
    );
    if (result != null) {
      final prefs = await SharedPreferences.getInstance();
      await prefs.setInt(_prefKeyMandiId,    result['id'] as int);
      await prefs.setString(_prefKeyMandiName, result['name'] as String);
      setState(() {
        _mandiId   = result['id'] as int;
        _mandiName = result['name'] as String;
      });
      await _fetchRates();
    }
  }

  List<MandiRate> get _filteredRates {
    final rates = _data?.rates ?? [];
    if (_category == 'all') return rates;
    return rates.where((r) => r.category == _category).toList();
  }

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final textColor = isDark ? AppColors.textPrimaryDark : AppColors.textPrimaryLight;

    return Scaffold(
      backgroundColor: isDark ? AppColors.scaffoldDark : AppColors.scaffoldLight,
      body: SafeArea(
        child: Column(
          children: [
            _buildHeader(isDark, textColor),
            _buildTabBar(isDark),
            Expanded(
              child: _loading
                  ? const Center(child: CircularProgressIndicator())
                  : _mandiId == null
                      ? _buildSelectMandiPrompt()
                      : _data == null || _filteredRates.isEmpty
                          ? _buildEmpty()
                          : _buildRateList(isDark, textColor),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildHeader(bool isDark, Color textColor) {
    return Container(
      padding: const EdgeInsets.fromLTRB(16, 16, 16, 8),
      color: isDark ? AppColors.cardDark : AppColors.cardLight,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              const Text('🌾', style: TextStyle(fontSize: 22)),
              const SizedBox(width: 8),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text('आज का मंडी भाव',
                        style: TextStyle(
                          fontSize: 18,
                          fontWeight: FontWeight.bold,
                          color: textColor,
                        )),
                    Text(
                      _data?.date != null
                          ? _formatDate(_data!.date)
                          : 'आज',
                      style: TextStyle(
                        fontSize: 12,
                        color: textColor.withAlpha(150),
                      ),
                    ),
                  ],
                ),
              ),
              IconButton(
                icon: const Icon(Icons.notifications_outlined),
                color: AppColors.primary,
                tooltip: 'मेरे अलर्ट',
                onPressed: () => Navigator.push(
                  context,
                  MaterialPageRoute(builder: (_) => const MyAlertsScreen()),
                ),
              ),
            ],
          ),
          const SizedBox(height: 8),
          GestureDetector(
            onTap: _selectMandi,
            child: Container(
              padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
              decoration: BoxDecoration(
                color: AppColors.primary.withAlpha(15),
                borderRadius: BorderRadius.circular(8),
                border: Border.all(color: AppColors.primary.withAlpha(80)),
              ),
              child: Row(
                children: [
                  const Icon(Icons.store_rounded, color: AppColors.primary, size: 18),
                  const SizedBox(width: 8),
                  Expanded(
                    child: Text(
                      _mandiName,
                      style: const TextStyle(
                        fontWeight: FontWeight.w600,
                        color: AppColors.primary,
                      ),
                    ),
                  ),
                  if (_distanceKm != null)
                    Text(
                      '${_distanceKm!} km',
                      style: TextStyle(fontSize: 12, color: textColor.withAlpha(150)),
                    ),
                  const SizedBox(width: 4),
                  const Icon(Icons.edit_outlined, size: 16, color: AppColors.primary),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildTabBar(bool isDark) {
    return Container(
      color: isDark ? AppColors.cardDark : AppColors.cardLight,
      child: TabBar(
        controller: _tabCtrl,
        isScrollable: true,
        labelColor: AppColors.primary,
        unselectedLabelColor: isDark ? AppColors.textSecondaryDark : AppColors.textSecondaryLight,
        indicatorColor: AppColors.primary,
        tabs: _categories.map((c) => Tab(text: c.$2)).toList(),
      ),
    );
  }

  Widget _buildRateList(bool isDark, Color textColor) {
    return RefreshIndicator(
      onRefresh: _fetchRates,
      child: ListView.builder(
        padding: const EdgeInsets.all(12),
        itemCount: _filteredRates.length,
        itemBuilder: (ctx, i) => _RateCard(
          rate: _filteredRates[i],
          isDark: isDark,
          mandiId: _mandiId!,
        ),
      ),
    );
  }

  Widget _buildSelectMandiPrompt() => Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Text('🌾', style: TextStyle(fontSize: 48)),
            const SizedBox(height: 12),
            const Text('अपनी मंडी चुनें', style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold)),
            const SizedBox(height: 8),
            ElevatedButton.icon(
              onPressed: _selectMandi,
              icon: const Icon(Icons.store_rounded),
              label: const Text('मंडी चुनें'),
              style: ElevatedButton.styleFrom(backgroundColor: AppColors.primary),
            ),
          ],
        ),
      );

  Widget _buildEmpty() => Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(Icons.info_outline, size: 48, color: Colors.grey.shade400),
            const SizedBox(height: 12),
            const Text('आज के भाव उपलब्ध नहीं हैं'),
          ],
        ),
      );

  String _formatDate(String iso) {
    final parts = iso.split('-');
    if (parts.length != 3) return iso;
    const months = ['', 'जनवरी', 'फरवरी', 'मार्च', 'अप्रैल', 'मई', 'जून',
                    'जुलाई', 'अगस्त', 'सितंबर', 'अक्टूबर', 'नवंबर', 'दिसंबर'];
    final m = int.tryParse(parts[1]) ?? 0;
    return '${int.parse(parts[2])} ${m < months.length ? months[m] : ''} ${parts[0]}';
  }
}

// ── Rate Card ────────────────────────────────────────────────────────────────

class _RateCard extends StatelessWidget {
  const _RateCard({
    required this.rate,
    required this.isDark,
    required this.mandiId,
  });

  final MandiRate rate;
  final bool      isDark;
  final int       mandiId;

  @override
  Widget build(BuildContext context) {
    final isUp   = rate.trend == 'up';
    final isDown = rate.trend == 'down';
    final trendColor = isUp
        ? Colors.green
        : isDown
            ? Colors.red
            : Colors.grey;

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
            builder: (_) => CommodityDetailScreen(
              commodityId: rate.commodityId,
              commodityName: rate.commodity,
              commodityEn: rate.commodityEn,
              mandiId: mandiId,
            ),
          ),
        ),
        child: Padding(
          padding: const EdgeInsets.all(12),
          child: Row(
            children: [
              // Name
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(rate.commodity,
                        style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 15)),
                    Text(rate.commodityEn,
                        style: TextStyle(
                          fontSize: 12,
                          color: isDark ? AppColors.textSecondaryDark : AppColors.textSecondaryLight,
                        )),
                    if (rate.arrivals != null)
                      Text('आवक: ${rate.arrivals}',
                          style: TextStyle(fontSize: 11, color: Colors.grey.shade500)),
                  ],
                ),
              ),
              // Price block
              Column(
                crossAxisAlignment: CrossAxisAlignment.end,
                children: [
                  Text(
                    '₹${rate.modalPrice.toStringAsFixed(0)}',
                    style: const TextStyle(fontSize: 20, fontWeight: FontWeight.bold),
                  ),
                  Text(
                    '${rate.minPrice.toStringAsFixed(0)} – ${rate.maxPrice.toStringAsFixed(0)}',
                    style: TextStyle(fontSize: 12, color: Colors.grey.shade500),
                  ),
                  if (rate.change != null)
                    Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Icon(
                          isUp ? Icons.arrow_upward_rounded
                              : isDown ? Icons.arrow_downward_rounded
                              : Icons.remove_rounded,
                          size: 14,
                          color: trendColor,
                        ),
                        Text(
                          '₹${rate.change!.abs().toStringAsFixed(0)}'
                          '${rate.changePercent != null ? ' (${rate.changePercent!.abs().toStringAsFixed(1)}%)' : ''}',
                          style: TextStyle(fontSize: 12, color: trendColor, fontWeight: FontWeight.w600),
                        ),
                      ],
                    ),
                ],
              ),
              // Alert bell
              IconButton(
                icon: const Icon(Icons.notifications_none_rounded, size: 20),
                color: AppColors.primary,
                onPressed: () => _showAlertDialog(context, rate),
              ),
            ],
          ),
        ),
      ),
    );
  }

  void _showAlertDialog(BuildContext context, MandiRate rate) {
    showDialog(
      context: context,
      builder: (ctx) => _AlertDialog(rate: rate, mandiId: mandiId),
    );
  }
}

// ── Set Alert Dialog ─────────────────────────────────────────────────────────

class _AlertDialog extends StatefulWidget {
  const _AlertDialog({required this.rate, required this.mandiId});
  final MandiRate rate;
  final int       mandiId;

  @override
  State<_AlertDialog> createState() => _AlertDialogState();
}

class _AlertDialogState extends State<_AlertDialog> {
  final _priceCtrl = TextEditingController();
  String _alertType = 'above';
  bool   _saving    = false;

  @override
  void initState() {
    super.initState();
    _priceCtrl.text = widget.rate.modalPrice.toStringAsFixed(0);
  }

  @override
  void dispose() {
    _priceCtrl.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      title: Text('${widget.rate.commodity} अलर्ट'),
      content: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Text('वर्तमान भाव: ₹${widget.rate.modalPrice.toStringAsFixed(0)}/${widget.rate.unit}'),
          const SizedBox(height: 12),
          SegmentedButton<String>(
            segments: const [
              ButtonSegment(value: 'above', label: Text('इससे ऊपर')),
              ButtonSegment(value: 'below', label: Text('इससे नीचे')),
            ],
            selected: {_alertType},
            onSelectionChanged: (s) => setState(() => _alertType = s.first),
          ),
          const SizedBox(height: 12),
          TextField(
            controller: _priceCtrl,
            keyboardType: TextInputType.number,
            decoration: const InputDecoration(
              labelText: 'लक्ष्य मूल्य (₹)',
              prefixText: '₹',
              border: OutlineInputBorder(),
            ),
          ),
        ],
      ),
      actions: [
        TextButton(onPressed: () => Navigator.pop(context), child: const Text('रद्द करें')),
        FilledButton(
          onPressed: _saving ? null : () => _save(context),
          child: _saving
              ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2))
              : const Text('सेट करें'),
        ),
      ],
    );
  }

  Future<void> _save(BuildContext ctx) async {
    final price = double.tryParse(_priceCtrl.text.trim());
    if (price == null || price <= 0) return;
    setState(() => _saving = true);
    final svc = MandiService();
    await svc.setAlert(
      firebaseUid: 'guest', // replace with actual firebase UID from auth provider
      commodityId: widget.rate.commodityId,
      mandiId: widget.mandiId,
      alertType: _alertType,
      targetPrice: price,
    );
    if (ctx.mounted) {
      Navigator.pop(ctx);
      ScaffoldMessenger.of(ctx).showSnackBar(
        SnackBar(content: Text('${widget.rate.commodity} का अलर्ट सेट हो गया ✓')),
      );
    }
  }
}
