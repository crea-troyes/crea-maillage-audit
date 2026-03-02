jQuery(function ($) {
  function setStatus(txt, isError) {
    $('#cma-status')
      .text(txt || '')
      .toggleClass('is-error', !!isError);
  }

  $('#cma-run-scan').on('click', function () {
    setStatus('Analyse en cours… (ne fermez pas la page)', false);

    $.post(CMA.ajaxUrl, {
      action: 'cma_run_scan',
      nonce: CMA.nonce
    })
      .done(function (res) {
        if (res && res.success) {
          setStatus(res.data.message + ' (' + res.data.counts.items + ' contenus)', false);
          window.location.reload();
        } else {
          setStatus((res && res.data && res.data.message) ? res.data.message : 'Erreur inconnue', true);
        }
      })
      .fail(function () {
        setStatus('Erreur AJAX (timeout serveur possible). On pourra passer en scan par batch si besoin.', true);
      });
  });

  $('#cma-clear-scan').on('click', function () {
    if (!confirm('Supprimer le cache de la dernière analyse ?')) return;

    setStatus('Suppression du cache…', false);

    $.post(CMA.ajaxUrl, {
      action: 'cma_clear_scan',
      nonce: CMA.nonce
    })
      .done(function (res) {
        if (res && res.success) {
          setStatus(res.data.message, false);
          window.location.reload();
        } else {
          setStatus((res && res.data && res.data.message) ? res.data.message : 'Erreur inconnue', true);
        }
      })
      .fail(function () {
        setStatus('Erreur AJAX', true);
      });
  });
});

jQuery(document).ready(function($) {
    $(document).on('click', '#tableau tbody:first-child th', function() {
        var $th = $(this);
        var $table = $('#tableau');
        
        var $tbodyData = $table.find('tbody').eq(1); 
        
        if ($tbodyData.length === 0) $tbodyData = $table.find('tbody').first();

        var index = $th.index();
        var isAsc = $th.attr('data-sort-dir') !== 'asc';

        var rows = $tbodyData.find('tr').filter(function() {
            return $(this).find('td').length > 0;
        }).get();

        rows.sort(function(a, b) {
            var valA = $(a).find('td').eq(index).text().trim();
            var valB = $(b).find('td').eq(index).text().trim();

            var numA = parseFloat(valA.replace(/[^\d.-]/g, ''));
            var numB = parseFloat(valB.replace(/[^\d.-]/g, ''));

            if (!isNaN(numA) && !isNaN(numB)) {
                return isAsc ? numA - numB : numB - numA;
            }
            return isAsc ? valA.localeCompare(valB, 'fr') : valB.localeCompare(valA, 'fr');
        });

        $tbodyData.empty();

        var fragment = document.createDocumentFragment();
        $.each(rows, function(i, row) {
            fragment.appendChild(row);
        });
        $tbodyData.append(fragment);

        $table.find('th').removeAttr('data-sort-dir');
        $th.attr('data-sort-dir', isAsc ? 'asc' : 'desc');
    });

    $('#tableau tbody:first-child th').css('cursor', 'pointer');
});


(function () {
    const graphContainer = document.getElementById('cma-graph');
    const wrapper = document.getElementById('cma-graph-wrapper');

    if (!graphContainer || typeof vis === 'undefined') return;

    const loader = document.createElement('div');
    loader.id = 'cma-loader';
    loader.innerHTML = '<div class="cma-spinner"></div><p>Calcul de la cartographie...</p>';
    loader.style = "position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); text-align:center; z-index:10;";
    graphContainer.style.position = 'relative';
    wrapper.appendChild(loader);

    const raw = graphContainer.dataset.graph;
    if (!raw) return;
    const graphData = JSON.parse(raw);

    const nodes = new vis.DataSet(graphData.nodes.map(node => {
        let color = node.type === 'page' ? '#065f46' : '#3730a3';
        if (node.is_isolated) color = '#dc2626';

        return {
            id: node.id,
            label: node.label,
            title: node.label,
            color: color,
            shape: 'dot',
            size: node.is_isolated ? 15 : 10
        };
    }));

    const edges = new vis.DataSet(graphData.edges.map(e => ({ from: e.from, to: e.to, arrows: 'to' })));

    const network = new vis.Network(graphContainer, { nodes, edges }, {
        physics: {
            stabilization: true
        },
        nodes: { font: { size: 12, color: '#333' } }
    });

    network.stabilize(200);

    network.once("stabilized", function () {
        loader.style.display = 'none';
        network.setOptions({ physics: false });
    });

    setTimeout(function () {
        loader.style.display = 'none';
        network.setOptions({ physics: false });
    }, 4000);
})();