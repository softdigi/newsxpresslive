import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';

import '../../../core/constants/app_colors.dart';
import '../../../data/models/agency_models.dart';
import '../../../providers/agency_provider.dart';

class AgencyAnalyticsScreen extends StatefulWidget {
  const AgencyAnalyticsScreen({super.key});

  @override
  State<AgencyAnalyticsScreen> createState() => _AgencyAnalyticsScreenState();
}

class _AgencyAnalyticsScreenState extends State<AgencyAnalyticsScreen> {
  DateTime _from = DateTime.now().subtract(const Duration(days: 30));
  DateTime _to = DateTime.now();

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _load());
  }

  void _load() {
    final f = '${_from.year}-${_from.month.toString().padLeft(2, '0')}-'
        '${_from.day.toString().padLeft(2, '0')}';
    final t = '${_to.year}-${_to.month.toString().padLeft(2, '0')}-'
        '${_to.day.toString().padLeft(2, '0')}';
    context.read<AgencyProvider>().loadRevenue(from: f, to: t);
  }

  Future<void> _pickDate({required bool isFrom}) async {
    final picked = await showDatePicker(
      context: context,
      initialDate: isFrom ? _from : _to,
      firstDate: DateTime(2020),
      lastDate: DateTime.now(),
    );
    if (picked == null) return;
    setState(() {
      if (isFrom) {
        _from = picked;
      } else {
        _to = picked;
      }
    });
    _load();
  }

  String _fmt(DateTime d) =>
      '${d.year}-${d.month.toString().padLeft(2, '0')}-'
      '${d.day.toString().padLeft(2, '0')}';

  void _exportCsv(AgencyRevenueSummary summary) {
    final buf = StringBuffer();
    buf.writeln('Title,Impressions,Clicks,Revenue,Agency Share');
    for (final r in summary.articleBreakdown) {
      buf.writeln(
          '"${r.title}",${r.impressions},${r.clicks},'
          '${r.revenue.toStringAsFixed(2)},${r.agencyShare.toStringAsFixed(2)}');
    }
    Clipboard.setData(ClipboardData(text: buf.toString()));
    ScaffoldMessenger.of(context).showSnackBar(
      const SnackBar(content: Text('CSV copied to clipboard')),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Consumer<AgencyProvider>(
      builder: (context, provider, _) {
        return Scaffold(
          backgroundColor: AppColors.scaffoldLight,
          appBar: AppBar(
            backgroundColor: AppColors.primary,
            foregroundColor: Colors.white,
            title: const Text('Analytics'),
          ),
          body: provider.isLoading && provider.revenueSummary == null
              ? const Center(child: CircularProgressIndicator())
              : RefreshIndicator(
                  onRefresh: () async => _load(),
                  child: ListView(
                    padding: const EdgeInsets.all(16),
                    children: [
                      // Date range
                      _card(
                        child: Row(
                          children: [
                            Expanded(
                              child: _DateField(
                                label: 'From',
                                value: _fmt(_from),
                                onTap: () => _pickDate(isFrom: true),
                              ),
                            ),
                            const SizedBox(width: 12),
                            Expanded(
                              child: _DateField(
                                label: 'To',
                                value: _fmt(_to),
                                onTap: () => _pickDate(isFrom: false),
                              ),
                            ),
                          ],
                        ),
                      ),
                      const SizedBox(height: 12),
                      if (provider.errorMsg != null)
                        Padding(
                          padding: const EdgeInsets.only(bottom: 12),
                          child: Text(provider.errorMsg!,
                              style:
                                  const TextStyle(color: AppColors.primary)),
                        ),
                      if (provider.revenueSummary != null) ...[
                        _MetricsRow(provider.revenueSummary!),
                        const SizedBox(height: 12),
                        _ShareCards(provider.revenueSummary!),
                        const SizedBox(height: 12),
                        _ArticleChart(provider.revenueSummary!.articleBreakdown),
                        const SizedBox(height: 12),
                        _ArticleTable(provider.revenueSummary!),
                        const SizedBox(height: 12),
                        SizedBox(
                          width: double.infinity,
                          child: ElevatedButton.icon(
                            onPressed: () => _exportCsv(provider.revenueSummary!),
                            icon: const Icon(Icons.download_outlined),
                            label: const Text('Export CSV (copy to clipboard)'),
                            style: ElevatedButton.styleFrom(
                              backgroundColor: AppColors.accent,
                              foregroundColor: Colors.white,
                              shape: RoundedRectangleBorder(
                                  borderRadius: BorderRadius.circular(10)),
                            ),
                          ),
                        ),
                      ],
                    ],
                  ),
                ),
        );
      },
    );
  }

  Widget _card({required Widget child}) {
    return Container(
      decoration: BoxDecoration(
        color: AppColors.cardLight,
        borderRadius: BorderRadius.circular(12),
        boxShadow: [
          BoxShadow(
              color: Colors.black.withOpacity(0.05),
              blurRadius: 6,
              offset: const Offset(0, 2)),
        ],
      ),
      padding: const EdgeInsets.all(16),
      child: child,
    );
  }
}

// ── Widgets ───────────────────────────────────────────────────────────────────

class _DateField extends StatelessWidget {
  const _DateField(
      {required this.label, required this.value, required this.onTap});
  final String label;
  final String value;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTap: onTap,
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
        decoration: BoxDecoration(
          border: Border.all(color: AppColors.divider),
          borderRadius: BorderRadius.circular(8),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(label,
                style: const TextStyle(
                    fontSize: 11, color: AppColors.textSecondaryLight)),
            const SizedBox(height: 2),
            Text(value,
                style: const TextStyle(
                    fontSize: 13, fontWeight: FontWeight.w600)),
          ],
        ),
      ),
    );
  }
}

class _MetricsRow extends StatelessWidget {
  const _MetricsRow(this.summary);
  final AgencyRevenueSummary summary;

  @override
  Widget build(BuildContext context) {
    final ctr = summary.impressions > 0
        ? (summary.clicks / summary.impressions * 100)
        : 0.0;
    final metrics = [
      _M('Impressions', summary.impressions.toString(), AppColors.accent),
      _M('Clicks', summary.clicks.toString(), Colors.blue),
      _M('CTR %', '${ctr.toStringAsFixed(2)}%', Colors.purple),
      _M('Gross Rev', '₹${summary.grossRevenue.toStringAsFixed(2)}',
          Colors.green),
    ];
    return GridView.count(
      crossAxisCount: 2,
      crossAxisSpacing: 12,
      mainAxisSpacing: 12,
      childAspectRatio: 1.6,
      shrinkWrap: true,
      physics: const NeverScrollableScrollPhysics(),
      children: metrics
          .map((m) => _MetricCard(m))
          .toList(),
    );
  }
}

class _M {
  final String label;
  final String value;
  final Color color;
  const _M(this.label, this.value, this.color);
}

class _MetricCard extends StatelessWidget {
  const _MetricCard(this.m);
  final _M m;

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
      padding: const EdgeInsets.all(14),
      child: Column(
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          Text(m.value,
              style: TextStyle(
                  fontSize: 20,
                  fontWeight: FontWeight.bold,
                  color: m.color)),
          const SizedBox(height: 4),
          Text(m.label,
              style: const TextStyle(
                  fontSize: 12, color: AppColors.textSecondaryLight)),
        ],
      ),
    );
  }
}

class _ShareCards extends StatelessWidget {
  const _ShareCards(this.summary);
  final AgencyRevenueSummary summary;

  @override
  Widget build(BuildContext context) {
    return Row(
      children: [
        Expanded(
          child: _shareCard('Your Share (40%)',
              '₹${summary.agencyShare.toStringAsFixed(2)}', Colors.green),
        ),
        const SizedBox(width: 12),
        Expanded(
          child: _shareCard('Platform Share (60%)',
              '₹${summary.platformShare.toStringAsFixed(2)}',
              AppColors.primary),
        ),
      ],
    );
  }

  Widget _shareCard(String label, String value, Color color) {
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
      padding: const EdgeInsets.all(14),
      child: Column(
        children: [
          Text(value,
              style: TextStyle(
                  fontSize: 18, fontWeight: FontWeight.bold, color: color)),
          const SizedBox(height: 4),
          Text(label,
              textAlign: TextAlign.center,
              style: const TextStyle(
                  fontSize: 11, color: AppColors.textSecondaryLight)),
        ],
      ),
    );
  }
}

class _ArticleChart extends StatelessWidget {
  const _ArticleChart(this.items);
  final List<ArticleRevenue> items;

  @override
  Widget build(BuildContext context) {
    if (items.isEmpty) return const SizedBox.shrink();
    final maxImp = items.fold<int>(0, (p, v) => v.impressions > p ? v.impressions : p);
    final top = items.take(5).toList();

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
          const Text('Article Impressions',
              style: TextStyle(
                  fontSize: 14,
                  fontWeight: FontWeight.bold,
                  color: AppColors.textPrimaryLight)),
          const SizedBox(height: 12),
          ...top.map((r) {
            final frac = maxImp > 0 ? r.impressions / maxImp : 0.0;
            return Padding(
              padding: const EdgeInsets.only(bottom: 8),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(r.title,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(fontSize: 11)),
                  const SizedBox(height: 4),
                  LayoutBuilder(builder: (ctx, box) {
                    return Stack(
                      children: [
                        Container(
                            height: 14,
                            width: box.maxWidth,
                            decoration: BoxDecoration(
                                color: AppColors.shimmerBase,
                                borderRadius: BorderRadius.circular(6))),
                        Container(
                            height: 14,
                            width: box.maxWidth * frac,
                            decoration: BoxDecoration(
                                color: AppColors.primary.withOpacity(0.7),
                                borderRadius: BorderRadius.circular(6))),
                      ],
                    );
                  }),
                  Text('${r.impressions} impressions',
                      style: const TextStyle(
                          fontSize: 10,
                          color: AppColors.textSecondaryLight)),
                ],
              ),
            );
          }),
        ],
      ),
    );
  }
}

class _ArticleTable extends StatelessWidget {
  const _ArticleTable(this.summary);
  final AgencyRevenueSummary summary;

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
      child: ClipRRect(
        borderRadius: BorderRadius.circular(12),
        child: SingleChildScrollView(
          scrollDirection: Axis.horizontal,
          child: DataTable(
            headingRowColor: WidgetStateProperty.all(
                AppColors.primary.withOpacity(0.08)),
            columns: const [
              DataColumn(label: Text('Title')),
              DataColumn(label: Text('Impr.'), numeric: true),
              DataColumn(label: Text('Clicks'), numeric: true),
              DataColumn(label: Text('Revenue'), numeric: true),
              DataColumn(label: Text('Share'), numeric: true),
            ],
            rows: summary.articleBreakdown
                .map((r) => DataRow(cells: [
                      DataCell(SizedBox(
                          width: 140,
                          child: Text(r.title,
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis))),
                      DataCell(Text(r.impressions.toString())),
                      DataCell(Text(r.clicks.toString())),
                      DataCell(
                          Text('₹${r.revenue.toStringAsFixed(2)}')),
                      DataCell(
                          Text('₹${r.agencyShare.toStringAsFixed(2)}')),
                    ]))
                .toList(),
          ),
        ),
      ),
    );
  }
}
