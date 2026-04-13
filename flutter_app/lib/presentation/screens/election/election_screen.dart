// flutter_app/lib/presentation/screens/election/election_screen.dart
// Election results screen with party seat chart, constituency table,
// district filter and 30-second auto-refresh.

import 'dart:async';
import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;
import '../../../core/constants/api_endpoints.dart';

class ElectionScreen extends StatefulWidget {
  const ElectionScreen({super.key});

  @override
  State<ElectionScreen> createState() => _ElectionScreenState();
}

class _ElectionScreenState extends State<ElectionScreen> {
  Map<String, dynamic>? _election;
  List<Map<String, dynamic>> _partySummary = [];
  List<Map<String, dynamic>> _constituencyResults = [];
  List<Map<String, dynamic>> _filteredResults = [];
  int _majorityMark = 0;
  bool _loading = true;
  String? _error;
  int? _selectedElectionId;
  int? _selectedDistrictId;
  Timer? _refreshTimer;

  // Track previous seat counts to detect seat flips (breaking news)
  final Map<String, int> _prevSeats = {};
  String? _breakingMsg;

  @override
  void initState() {
    super.initState();
    // Default to first election — in a real app you would list elections first
    _selectedElectionId = 1;
    _fetchResults();
    _refreshTimer = Timer.periodic(
      const Duration(seconds: 30),
      (_) => _fetchResults(silent: true),
    );
  }

  @override
  void dispose() {
    _refreshTimer?.cancel();
    super.dispose();
  }

  Future<void> _fetchResults({bool silent = false}) async {
    if (!silent) setState(() { _loading = true; _error = null; });

    try {
      final uri = Uri.parse(ApiEndpoints.electionResults).replace(queryParameters: {
        'election_id': '$_selectedElectionId',
        if (_selectedDistrictId != null) 'district_id': '$_selectedDistrictId',
      });

      final response = await http.get(uri).timeout(const Duration(seconds: 15));
      if (!mounted) return;

      final data = jsonDecode(response.body) as Map<String, dynamic>;

      if (data['success'] == true) {
        // Detect seat flip (breaking ticker)
        final newParties = (data['party_summary'] as List<dynamic>)
            .cast<Map<String, dynamic>>();
        String? flip;
        for (final p in newParties) {
          final short = p['party_short'] as String;
          final seats = int.tryParse('${p['seats_won']}') ?? 0;
          if (_prevSeats.containsKey(short) && _prevSeats[short] != seats) {
            flip = '${p['party_name']}: ${_prevSeats[short]} → $seats seats';
          }
          _prevSeats[short] = seats;
        }

        final results = (data['constituency_results'] as List<dynamic>)
            .cast<Map<String, dynamic>>();

        setState(() {
          _election        = data['election'] as Map<String, dynamic>;
          _partySummary    = newParties;
          _constituencyResults = results;
          _filteredResults = results;
          _majorityMark    = int.tryParse('${data['majority_mark']}') ?? 0;
          _loading         = false;
          if (flip != null) _breakingMsg = '🔴 Seat Flip: $flip';
        });
      } else {
        if (!silent) setState(() { _error = data['error'] ?? 'Failed'; _loading = false; });
      }
    } catch (e) {
      if (!silent && mounted) setState(() { _error = e.toString(); _loading = false; });
    }
  }

  void _applyDistrictFilter(int? districtId) {
    setState(() {
      _selectedDistrictId = districtId;
      _selectedElectionId = _selectedElectionId;
    });
    _fetchResults();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Election Results'),
        backgroundColor: const Color(0xFF1a1a2e),
        actions: [
          IconButton(
            icon: const Icon(Icons.refresh),
            onPressed: _fetchResults,
          ),
        ],
      ),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : _error != null
              ? Center(child: Text(_error!, style: const TextStyle(color: Colors.red)))
              : _buildBody(),
    );
  }

  Widget _buildBody() {
    return ListView(
      padding: const EdgeInsets.all(12),
      children: [
        // Breaking news ticker
        if (_breakingMsg != null)
          Container(
            color: Colors.red.shade700,
            padding: const EdgeInsets.symmetric(vertical: 8, horizontal: 12),
            child: Text(
              _breakingMsg!,
              style: const TextStyle(color: Colors.white, fontWeight: FontWeight.bold),
            ),
          ),

        // Election header
        if (_election != null) _buildElectionHeader(),
        const SizedBox(height: 12),

        // Party seat chart
        if (_partySummary.isNotEmpty) ...[
          const Text('Party Seats', style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold)),
          const SizedBox(height: 8),
          _buildPartySeatChart(),
          const SizedBox(height: 16),
        ],

        // District filter
        _buildDistrictFilter(),
        const SizedBox(height: 12),

        // Constituency results table
        if (_filteredResults.isNotEmpty) ...[
          const Text('Constituency Results', style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold)),
          const SizedBox(height: 8),
          _buildConstituencyTable(),
        ],
      ],
    );
  }

  Widget _buildElectionHeader() {
    final e = _election!;
    final statusColor = e['status'] == 'declared'
        ? Colors.green
        : e['status'] == 'counting'
            ? Colors.orange
            : Colors.blue;

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(e['name'] ?? '', style: const TextStyle(fontSize: 18, fontWeight: FontWeight.bold)),
            Text(e['name_hi'] ?? '', style: const TextStyle(color: Colors.grey)),
            const SizedBox(height: 4),
            Row(children: [
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                decoration: BoxDecoration(color: statusColor, borderRadius: BorderRadius.circular(12)),
                child: Text(
                  '${e['status']}'.toUpperCase(),
                  style: const TextStyle(color: Colors.white, fontSize: 11),
                ),
              ),
              const SizedBox(width: 8),
              Text('Majority: $_majorityMark seats', style: const TextStyle(fontSize: 12)),
            ]),
            if (e['last_updated'] != null)
              Text('Updated: ${e['last_updated']}', style: const TextStyle(fontSize: 11, color: Colors.grey)),
          ],
        ),
      ),
    );
  }

  Widget _buildPartySeatChart() {
    final totalSeats = _partySummary.fold<int>(
      0, (sum, p) => sum + (int.tryParse('${p['seats_won']}') ?? 0),
    );

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(
          children: _partySummary.map((party) {
            final seated = int.tryParse('${party['seats_won']}') ?? 0;
            final leading = int.tryParse('${party['seats_leading']}') ?? 0;
            final total = totalSeats > 0 ? totalSeats : 1;
            final frac = seated / total;
            final colorHex = (party['party_color'] as String?)?.replaceAll('#', '') ?? '333333';
            final color = Color(int.parse('FF$colorHex', radix: 16));

            return Padding(
              padding: const EdgeInsets.symmetric(vertical: 4),
              child: Row(children: [
                SizedBox(
                  width: 48,
                  child: Text(
                    party['party_short'] ?? '',
                    style: TextStyle(color: color, fontWeight: FontWeight.bold, fontSize: 12),
                  ),
                ),
                Expanded(
                  child: Stack(children: [
                    Container(height: 20, color: Colors.grey.shade200),
                    FractionallySizedBox(
                      widthFactor: frac.clamp(0.0, 1.0),
                      child: Container(height: 20, color: color),
                    ),
                  ]),
                ),
                const SizedBox(width: 8),
                Text(
                  '$seated${leading > 0 ? '+$leading' : ''}',
                  style: const TextStyle(fontSize: 12, fontWeight: FontWeight.bold),
                ),
              ]),
            );
          }).toList(),
        ),
      ),
    );
  }

  Widget _buildDistrictFilter() {
    return Row(children: [
      const Text('District: '),
      const SizedBox(width: 8),
      DropdownButton<int?>(
        value: _selectedDistrictId,
        hint: const Text('All Districts'),
        items: [
          const DropdownMenuItem<int?>(value: null, child: Text('All Districts')),
          // In production these would be fetched from the geo API
        ],
        onChanged: _applyDistrictFilter,
      ),
    ]);
  }

  Widget _buildConstituencyTable() {
    return SingleChildScrollView(
      scrollDirection: Axis.horizontal,
      child: DataTable(
        headingRowColor: WidgetStateProperty.all(const Color(0xFF1a1a2e)),
        headingTextStyle: const TextStyle(color: Colors.white, fontWeight: FontWeight.bold),
        columnSpacing: 16,
        columns: const [
          DataColumn(label: Text('Constituency')),
          DataColumn(label: Text('Winner')),
          DataColumn(label: Text('Party')),
          DataColumn(label: Text('Votes'), numeric: true),
          DataColumn(label: Text('Margin'), numeric: true),
          DataColumn(label: Text('Status')),
        ],
        rows: _filteredResults.map((r) {
          final status = r['result_status'] as String? ?? 'counting';
          final statusColor = status == 'won'
              ? Colors.green
              : status == 'leading'
                  ? Colors.orange
                  : Colors.grey;
          return DataRow(cells: [
            DataCell(Text(r['constituency_name'] ?? '', overflow: TextOverflow.ellipsis)),
            DataCell(Text(r['winning_candidate'] ?? '—', overflow: TextOverflow.ellipsis)),
            DataCell(Text(r['winning_party_short'] ?? '—')),
            DataCell(Text(_fmt(r['winning_votes']))),
            DataCell(Text(_fmt(r['winning_margin']))),
            DataCell(Container(
              padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
              decoration: BoxDecoration(color: statusColor, borderRadius: BorderRadius.circular(8)),
              child: Text(status, style: const TextStyle(color: Colors.white, fontSize: 11)),
            )),
          ]);
        }).toList(),
      ),
    );
  }

  String _fmt(dynamic n) {
    final v = int.tryParse('$n') ?? 0;
    if (v >= 100000) return '${(v / 100000).toStringAsFixed(1)}L';
    if (v >= 1000)   return '${(v / 1000).toStringAsFixed(1)}K';
    return '$v';
  }
}
