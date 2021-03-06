import AbstractTextComposer from '@/public/composers/AbstractTextComposer';
import h from '@/public/js/helper';
import Pattern from '@/public/composers/Pattern';
import SvgOverlay from '@/public/googleMaps/SvgOverlay';
import Mercator from '@/public/js/Mercator';
import SVG from 'svg.js';

export default SvgComposer;

function SvgComposer(mapsApi, activeSelectedMetric) {
	// api usada de geojson2svg en js: https://github.com/gagan-bansal/geojson2svg
	// api usada de svg: http://svgjs.com/elements/#svg-pattern
	// posible api de php https://github.com/chrishadi/geojson2svg/blob/master/geojson2svg.php
	this.MapsApi = mapsApi;
	this.activeSelectedMetric = activeSelectedMetric;
	this.keysInTile = [];
	this.styles = [];
	this.svgInTile = [];
	this.index = this.activeSelectedMetric.index;
	this.labelsVisibility = [];
	this.AbstractConstructor();
	this.useGradients = window.SegMap.Configuration.UseGradients;
};

SvgComposer.prototype = new AbstractTextComposer();
SvgComposer.uniqueCssId = 1;

SvgComposer.prototype.render = function (mapResults, dataResults, gradient, tileKey, div, x, y, z, tileBounds) {
	var filtered = [];
	var allKeys = [];
	var mapItems = mapResults.Data.features;
	var projected = mapResults.Data.projected;
	var dataItems = dataResults.Data;
	if (this.activeSelectedMetric.HasSelectedVariable() === false) {
		return;
	}
	var variableId = this.activeSelectedMetric.SelectedVariable().Id;
	var patternValue = parseInt(this.activeSelectedMetric.GetPattern());
	var tileUniqueId = SvgComposer.uniqueCssId++;
	var id;
	var varId;
	var iMapa = 0;
	this.UpdateTextStyle(z);
	var colorMap = this.activeSelectedMetric.GetStyleColorDictionary();
	this.styles = [];

	if (mapItems.length === 0) return;

	for (var i = 0; i < dataItems.length; i++) {
		varId = dataItems[i]['VariableId'];
		if (varId === variableId) {
			id = dataItems[i]['FID'];
			var fid = parseFloat(id);
			// avanza en mapa
			while (mapItems[iMapa].id < fid) {
				if (++iMapa === mapItems.length) {
					break;
				}
			}
			if (iMapa === mapItems.length) {
				break;
			}
			if (mapItems[iMapa].id == fid) {
				this.processFeature(tileUniqueId, id, dataItems[i], mapItems[iMapa], tileKey, tileBounds, filtered, allKeys, patternValue, colorMap);
			}
		}
	}
	this.keysInTile[tileKey] = allKeys;
	var svg = this.CreateSVGOverlay(tileUniqueId, div, filtered, projected, tileBounds, z, patternValue, gradient);

	if (svg !== null) {
		var v = this.activeSelectedMetric.SelectedVariable().Id;
		var simpleKey = h.getVariableFrameKey(v, x, y, z, this.MapsApi.TileBoundsRequired());
		this.svgInTile[simpleKey] = svg;
	}
};

SvgComposer.prototype.processFeature = function (tileUniqueId, id, dataElement, mapElement, tileKey, tileBounds, filtered, allKeys, patternValue, colorMap) {
	// Se fija si por etiqueta está visible
	var val = dataElement['ValueId'];
	var valKey = 'K' + val;
	if (!(valKey in this.labelsVisibility)) {
		this.labelsVisibility[valKey] = this.activeSelectedMetric.ResolveVisibility(val);
	}
	if (this.labelsVisibility[valKey] === false) {
		return;
	}
	// Lo agrega
	var centroid = 	this.getCentroid(mapElement);
	var mapItem = {
		id: mapElement.id, type: mapElement.type, geometry: mapElement.geometry, properties: { className: 'e' + tileUniqueId + '_' + val }
	};
	if (!this.activeSelectedMetric.SelectedLevel().Dataset.ShowInfo) {
		mapItem.id = null;
	}
	if (dataElement.Description) {
		mapItem.properties.description = dataElement.Description;
	}
	if (!this.activeSelectedMetric.SelectedVariable().IsSimpleCount) {
		mapItem.properties.value = this.FormatValue(dataElement);
	}
	if (this.patternUseFillStyles(patternValue)) {
		mapItem.properties.style = 'fill: url(#cs' + val + ');';
	}

	this.AddFeatureText(val, mapElement, dataElement, centroid, tileKey, tileBounds, colorMap);

	filtered.push(mapItem);
};


SvgComposer.prototype.getCentroid = function (mapElement) {
	if (mapElement['properties'] && mapElement['properties'].centroid) {
		return new window.google.maps.LatLng(mapElement['properties'].centroid[0], mapElement['properties'].centroid[1]);
	} else {
		return h.getGeojsonCenter(mapElement);
	}
};

SvgComposer.prototype.AddFeatureText = function (val, mapElement, dataElement, centroid, tileKey, tileBounds, colorMap) {
	if (this.inTile(tileBounds, centroid)) {
		this.ResolveValueLabel(dataElement, centroid, tileKey, colorMap[val]);
	}
};

SvgComposer.prototype.SVG = function (h, w, z) {
	var xmlns = 'http://www.w3.org/2000/svg';
	var boxWidth = h;
	var boxHeight = w;

	var svgElem = document.createElementNS(xmlns, 'svg');
	svgElem.setAttributeNS(null, 'width', boxWidth);
	svgElem.setAttributeNS(null, 'height', boxHeight);
	svgElem.setAttributeNS(null, 'isFIDContainer', 1);
	svgElem.setAttributeNS(null, 'metricId', this.activeSelectedMetric.properties.Metric.Id);
	svgElem.setAttributeNS(null, 'metricVersionId', this.activeSelectedMetric.SelectedVersion().Version.Id);
	svgElem.setAttributeNS(null, 'levelId', this.activeSelectedMetric.SelectedLevel().Id);
	svgElem.setAttributeNS(null, 'variableId', this.activeSelectedMetric.SelectedVariable().Id);
	svgElem.style.display = 'block';
	var patternValue = parseInt(this.activeSelectedMetric.GetPattern());
	if (patternValue === 0 || patternValue === 2) {
		svgElem.style.strokeWidth = (z < 16 ? '1.5px' : '2px');
	} else if (patternValue === 1) {
		svgElem.style.strokeWidth = (z < 16 ? '2.5px' : '4px');
	} else if (this.patternIsPipeline(patternValue)) {
		// 3,4,5,6 son cañerías
		svgElem.style.strokeWidth = '0px';
	} else {
		svgElem.style.strokeWidth = (z < 16 ? '1.5px' : '2px');
		svgElem.style.strokeOpacity = this.activeSelectedMetric.currentOpacity;
	}
	return svgElem;
};

SvgComposer.prototype.patternUseFillStyles = function (patternValue) {
	return (patternValue > 2);
};

SvgComposer.prototype.patternIsPipeline = function (patternValue) {
	return (patternValue >= 3 && patternValue <= 6);
};

SvgComposer.prototype.CreateSVGOverlay = function (tileUniqueId, div, features, projected, tileBounds, z, patternValue, gradient) {
	var m = new Mercator();
	var projectedFeatures;
	if (projected) {
		projectedFeatures = {
			type: 'FeatureCollection',
			features: features
		};
	} else {
		projectedFeatures = m.ProjectGeoJsonFeatures(features);
	}

	var mercator = new Mercator();
	var min = mercator.fromLatLngToPoint({ lat: tileBounds.Min.Lat, lng: tileBounds.Min.Lon });
	var max = mercator.fromLatLngToPoint({ lat: tileBounds.Max.Lat, lng: tileBounds.Max.Lon });

	var attributes = [{ property: 'id', type: 'dynamic', key: 'FID' },
		{ property: 'properties.className', type: 'dynamic', key: 'class' },
		{ property: 'properties.description', type: 'dynamic', key: 'description' },
		{ property: 'properties.value', type: 'dynamic', key: 'value' }];

	if (this.patternUseFillStyles(patternValue)) {
		attributes.push({ property: 'properties.style', type: 'dynamic', key: 'style' });
	}
	var options = {
		viewportSize: { width: 256, height: 256 },
		attributes: attributes,
		mapExtent: { left: min.x, bottom: -max.y, right: max.x, top: -min.y },
		output: 'svg'
	};

	var geojson2svg = require('geojson2svg');
	var converter = geojson2svg(options);
	var parseSVG = require('parse-svg');
	var svgStrings = converter.convert(projectedFeatures, options);

	var oSvg = this.SVG(256, 256, z);

	var o2 = SVG.adopt(oSvg);

	var scales = this.defineScaleCriteria(z);

	var labels = this.activeSelectedMetric.GetStyleColorList(z);
	if (this.patternUseFillStyles(patternValue)) {
		this.appendPatterns(o2, labels, scales);
	}

	var maskId = null;
	if (this.useGradients && gradient) {
		// test mask: https://jsfiddle.net/ycLsr32k/
		var image = o2.image("data:" + gradient.ImageType + ";base64," + gradient.Data, 256, 256);
		// rectángulo de transparencia global
		var rect = o2.rect(256, 256);
		rect.style('fill: #FFFFFF;');
		rect.attr('class', 'gAlpha');
		var rect2 = o2.rect(256, 256);
		rect2.style('fill: #FFFFFF; opacity: ' + gradient.Luminance);
		// rectángulo de transparencia local
		var mask = o2.mask();
		mask.add(image);
		mask.add(rect);
		mask.add(rect2);
		maskId = 'svgMasks' + tileUniqueId;
		mask.attr('id', maskId);
	}

	this.appendStyles(oSvg, tileUniqueId, labels, patternValue, maskId);

	svgStrings.forEach(function (svgStr) {
		var svg = parseSVG(svgStr);
		oSvg.appendChild(svg);
	});

	h.removeAllChildren(div);
	div.appendChild(oSvg);
	return oSvg;
};

SvgComposer.prototype.appendPatterns = function (o2, labels, scales) {
	// crea un pattern para cada tipo de etiqueta
	var patternValue = parseInt(this.activeSelectedMetric.GetPattern());
	var width = (this.patternIsPipeline(patternValue) ? '; stroke-width: ' + scales.ang + 'px;' : '');
	for (var l = 0; l < labels.length; l++) {
		var pattern = this.createPattern(o2, scales);
		pattern.attr('id', labels[l].cs);
		if (patternValue === 11) {
			pattern.style('fill: ' + labels[l].fillColor + ';');
		}
		pattern.style('stroke: ' + labels[l].fillColor + '; stroke-opacity: ' + this.activeSelectedMetric.currentOpacity + width);
	}
};

SvgComposer.prototype.appendStyles = function (oSvg, tileUniqueId, labels, patternValue, mask) {
	// crea una clase para cada tipo de etiqueta
	var styles = "<style type='text/css'>";
	var fillBlock = (patternValue === 1 || patternValue === 2 ? '; fill: transparent ' : '');
	var maskBlock = (mask ? '; mask: url(#' + mask + ')' : '');

	for (var l = 0; l < labels.length; l++) {
		if (patternValue === 0) {
			styles += '.e' + tileUniqueId + '_' + labels[l].className +
				' { stroke: ' + labels[l].fillColor + '; stroke-opacity: ' + (this.activeSelectedMetric.currentOpacity * 1.1) +
				maskBlock + '; fill: ' + labels[l].fillColor + '; fill-opacity: ' + this.activeSelectedMetric.currentOpacity + ' } ';
		} else {
			styles += '.e' + tileUniqueId + '_' + labels[l].className +
				' { stroke: ' + labels[l].fillColor + '; stroke-opacity: ' + this.activeSelectedMetric.currentOpacity +
					fillBlock + maskBlock + ' } ';
		}
	}
	styles += '</style>';

	var parseSVG = require('parse-svg');
	var svgStyles = parseSVG(styles);
	oSvg.appendChild(svgStyles);
};

SvgComposer.prototype.createPattern = function (o2, scale) {
	var pattern = new Pattern(this.activeSelectedMetric.GetPattern(), scale);
	return pattern.GetPattern(o2);
};

SvgComposer.prototype.dispose = function () {
	this.clearText();
};

SvgComposer.prototype.removeTileFeatures = function (tileKey) {
	this.clearTileText(tileKey);
	if (this.svgInTile.hasOwnProperty(tileKey)) {
		delete this.svgInTile[tileKey];
	}
};

SvgComposer.prototype.defineScaleCriteria = function(z) {
	var divi = 4;
	switch (z) {
	case 11:
	case 12:
		divi = 2;
		break;
	case 13:
	case 14:
		divi = 1;
		break;
	case 15:
	case 16:
		divi = 0.5;
		break;
	case 17:
		divi = 0.25;
		break;
	case 18:
	case 19:
	case 20:
	case 21:
		divi = 0.125;
		break;
	}
	var v4 = 128 / divi;
	var v3 = 96 / divi;
	var v2 = 64 / divi;
	var v1 = 32 / divi;

	var anc = 4;
	var ang = 2;
	if (divi === 2) {
		anc = 3;
		ang = 1;
	}
	if (divi < 1) {
		anc = 3 / divi;
		ang = 1 / divi;
	}
	if (z <= 10) {
		ang = 1;
		anc = ang;
	}
	if (z >= 17) {
		anc = ang;
	}
	return { v1: v1, v2: v2, v3: v3, v4: v4, anc, ang, divi };
};
