
const map_el = document.querySelector(".map");

var map = L.map(map_el);

map.addControl(new L.Control.Fullscreen({pseudoFullscreen: true}));

const osm = L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '&copy; <a href="http://www.openstreetmap.org/copyright">OpenStreetMap</a>'
}).addTo(map);

const ocm = L.tileLayer('https://api.thunderforest.com/cycle/{z}/{x}/{y}.png?apikey=c3cba1466b3341ec9ed2c12986c8d979', {
    maxZoom: 19,
    attribution: '&copy; <a href="https://www.thunderforest.com/">Thunderforest</a>'
});

const cyclosm = L.tileLayer('https://{s}.tile-cyclosm.openstreetmap.fr/cyclosm/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '&copy; <a href="https://github.com/cyclosm/cyclosm-cartocss-style/releases">CyclOSM</a>'
});

const base_maps = {
    "OpenStreetMap": osm,
    "OpenCycleMap" : ocm,
    "CyclOSM"      : cyclosm
};

const statshunters_layer = new L.LayerGroup().addTo(map);

const overlay_maps = {
    "StatsHunters Tiles": statshunters_layer,
};

const layer_control = new L.Control.Layers(base_maps, overlay_maps).addTo(map);

var polyline = atob(map_el.getAttribute('data-polyline'));
var route = L.Polyline.fromEncoded(polyline);

route.addTo(map);

map.fitBounds(route.getBounds());

var decorator = L.polylineDecorator(route.getLatLngs(), {
patterns: [
  {
    offset: '17%',
    repeat: '33%',
    symbol: L.Symbol.arrowHead({pixelSize: 10, polygon: false, pathOptions: {stroke: true}})
  }
]
}).addTo(map);

L.marker(route.getLatLngs()[0]).addTo(map);

const statshunters_tile_zoom = 14;
// Functions lat/lng to/from Open Street Map tile numbers
function lon2tile(lon, zoom) {
    return (Math.floor((lon+180)/360*Math.pow(2,zoom)));
}

function lat2tile(lat,zoom)  {
    return (Math.floor((1-Math.log(Math.tan(lat*Math.PI/180) + 1/Math.cos(lat*Math.PI/180))/Math.PI)/2 *Math.pow(2,zoom)));
}

function tile2long(x,z) {
    return (x/Math.pow(2,z)*360-180);
}

function tile2lat(y,z) {
    var n=Math.PI-2*Math.PI*y/Math.pow(2,z);
    return (180/Math.PI*Math.atan(0.5*(Math.exp(n)-Math.exp(-n))));
}

tile_options = {
    color: 'red',
    weight: 1,
    fillOpacity: 0.1,
};



tiles.forEach((tile) => {

    let parts = tile.split("-");
    let xtile = parseInt(parts[0]);
    let ytile = parseInt(parts[1]);

    let tile_latlngs = [
        [tile2lat(ytile  , statshunters_tile_zoom) , tile2long(xtile  , statshunters_tile_zoom)],
        [tile2lat(ytile  , statshunters_tile_zoom) , tile2long(xtile+1, statshunters_tile_zoom)],
        [tile2lat(ytile+1, statshunters_tile_zoom) , tile2long(xtile+1, statshunters_tile_zoom)],
        [tile2lat(ytile+1, statshunters_tile_zoom) , tile2long(xtile  , statshunters_tile_zoom)],
    ];
    
    statshunters_layer.addLayer(new L.Polygon(tile_latlngs, tile_options));
});

/*
let tiles = {};
route.getLatLngs().forEach((rll) => {
    let tile = lon2tile(rll.lng, stats_hunter_zoom) + "-" + lat2tile(rll.lat, stats_hunter_zoom);
    if(!(tile in tiles)) {
        tiles[tile] = true;
    }
});
console.log(Object.keys(tiles).length + " tiles");
*/

var circle_marker = L.circleMarker([0,0], {radius:8} ).setStyle({color: 'magenta'}).addTo(map);

var latlngs = L.GeoJSON.coordsToLatLngs(elevation_geojson.coordinates); // assumes simple {"type": "LineString", "coordinates": [...

var points = {
  uphill: [],
  downhill: [],
  steep: []
};

let distance = 0;
let prev_alt = 0;
let current_series = 'none';
for (let i = 1; i < latlngs.length ; i++) {

    const dd = latlngs[i].distanceTo(latlngs[i-1]);
    distance += dd;
    const d = distance/1000;

    const grade = (latlngs[i].alt - prev_alt) / dd;
    //console.log((distance/1000).toFixed(3), (grade*100).toFixed(1) + '%', grade > 0.05 ? 'steep':'');

    if(latlngs[i].alt > prev_alt) {
      next_series = (grade > 0.05) ? 'steep':'uphill';
    }
    else {
      next_series = 'downhill'
    }

    // if continuiing the same series
    if(next_series == current_series) {

      let other_series_list = Object.keys(points);
      const index = other_series_list.indexOf(current_series);
      if (index > -1) {
        other_series_list.splice(index, 1);
      }

      // push all other series to null
      other_series_list.forEach(s => {
        points[s].push([d, null]);
      });
    }
    else // changing series from current_series to next_series
    {
      // fixup previous from_series
      if(current_series != 'none') {

        let other_series_list = Object.keys(points);
        const index = other_series_list.indexOf(next_series);
        if (index > -1) {
          other_series_list.splice(index, 1);
        }

        other_series_list.forEach(s => {
          if(points[s].length) {
            const prev_point = points[s][points[s].length-1];
            points[s].push([prev_point[0], null]);
          }
          points[s].push([d, null]);
      });
    }

      // fixup next series
      if(points[next_series].length) {
        const prev_point = points[next_series].pop();
        points[next_series].push([prev_point[0], prev_alt]);
      }
    }
    points[next_series].push([d, latlngs[i].alt]);
    current_series = next_series;
    prev_alt = latlngs[i].alt;
};

var altitude_options = {
    title: {
      text: 'Route Altitude',
      align: 'left'
    },
    colors: ['#26eaac', '#ffb926', '#ff4c6c'],
    series: [
      {
        name: 'Downhill',
        data: points['downhill']
      },
      {
        name: 'Uphill',
        data: points['uphill']
      },
      {
        name: 'Steep',
        data: points['steep']
      },
    ],
    fill : {
      type: 'solid'
    },
    stroke: {
      curve: 'straight'
    },
    chart: {
        type: 'area',
        height: 280,
        events: {
          mouseMove: function(event, chartContext, opts) {

            if(opts.dataPointIndex >= 0) {

                //console.log(opts);
                var distance_along = opts.config.series[opts.seriesIndex].data[opts.dataPointIndex][0]

                var length = L.GeometryUtil.length(latlngs);
                var ratio = (distance_along * 1000) / length;
                //console.log(distance_along, ratio);

                var ll = L.GeometryUtil.interpolateOnLine(map, latlngs, ratio);
                //console.log(ll);

                circle_marker.setLatLng(ll.latLng);
                map.panInside(ll.latLng, {padding: [50,50]});
            }
          },
      },
      animations: {
        enabled: false
      },
    },
    dataLabels: {
      enabled: false
    },
    markers : {
      size: 1,
      strokeOpacity: 0.01,
      fillOpacity: 0.01,
      hover: {size: 1},
    },
    legend : {
      labels: {
        useSeriesColors: true,
      },
      markers: {
        shape: 'square',
      },
      formatter: function(seriesName, opts) {
        // TODO calculate % of 'steep'
        return [seriesName, (seriesName == 'Steep' ? " (xx%) " :"")]
    }
    },
    xaxis: {
        type: 'numeric',
        title: { text: 'Distance (km)' }
    },
    yaxis: {
       title: { text: 'Height (m)' }
    },
    tooltip: {
      shared: true,
      intersect: false,
      hideEmptySeries: false,
      x : { formatter: function (val, { series, seriesIndex, dataPointIndex, w }) {
        return 'Distance: ' + val/*.toFixed(2)*/ + ' km';
      }},
      y : { formatter: function (val, { series, seriesIndex, dataPointIndex, w }) {
        return val ? val + 'm' : 'empty';
      }},
      custom: function({series, seriesIndex, dataPointIndex, w}) {

        const distance = w.config.series[seriesIndex].data[dataPointIndex][0];

        let matching_series = [];
        let matching_alt;

        for(let s = 0; s < w.config.series.length; s++) {

          let point = w.config.series[s].data.find((element) => element[0] == distance)
          if(point[1]) {
            matching_series.push('<span style="color:' + w.config.colors[s] + '">' +  w.config.series[s].name + '</span>');
            matching_alt = point[1];
          }
        };

        return '<div class="my-tooltip">' +
          '<div class="my-tooltip-header">' +
          'Distance: ' + distance.toFixed(2) + 'km'+
          '</div>'+
          '<div class="my-tooltip-body">' +
            matching_series.join(' ') + ': ' + matching_alt + 'm' +
          '</div>'+
          '</div>';
      }
    },
  };


  var altitude_chart = new ApexCharts(document.querySelector("#altitude_chart"), altitude_options);
  altitude_chart.render();


