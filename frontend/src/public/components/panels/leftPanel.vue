<template>
	<div>
		<div v-show='hasContent && !collapsed' class='left-panelPositionEnum' :style="{ width: width + 'px' }">
			<div v-if="isFullFront">
				<transition name="fade" mode='out-in'>
				<feature-list :panelInfo='Full' v-if='isFullList' @clickClose='doClose'/>
					<feature-info :panelInfo='Full' v-if='isFullInfo' @clickClose='doClose'/>
				</transition>
			</div>
			<div id="panTop" class="split" v-if="!isFullFront">
				<transition name="fade" mode='out-in'>
				<feature-list :panelInfo='Top' v-if='isTopList' @clickClose='doClose'/>
					<feature-info :panelInfo='Top' v-if='isTopInfo' @clickClose='doClose'/>
				</transition>
			</div>
			<div id="panBottom" class="split" v-if="!isFullFront">
				<transition name="fade" mode='out-in'>
				<feature-list :panelInfo='Bottom' v-if='isBottomList' @clickClose='doClose'/>
					<feature-info :panelInfo='Bottom' v-if='isBottomInfo' @clickClose='doClose'/>
				</transition>
			</div>
		</div>
		<collapse-button v-if='hasContent' :startLeft='width' :collapsed='collapsed' @click="doToggle" />
	</div>
</template>

<script>
import FeatureInfo from '@/public/components/widgets/features/featureInfo';
import FeatureList from '@/public/components/widgets/features/featureList';
import PanelType from '@/public/enums/PanelType';
import CollapseButton from '@/public/components/controls/collapseButton';
import Split from 'split.js';
import h from '@/public/js/helper';
export default {
	name: 'leftPanel',
	components: {
		FeatureInfo,
		FeatureList,
		CollapseButton,
	},
	data() {
		return {
			width: 300,
			hasContent: false,
			collapsed: true,
			Top: null,
			Bottom: null,
			Full: null,
			isFullFront: true,
			onlyFull: false,
			split: null,
			topHeight: 50,
		};
	},
	// created () { },
	mounted() {
		this.initSplit();
		window.addEventListener('resize', this.onResize);
	},
	beforeDestroy () {
		window.removeEventListener('resize', this.onResize);
	},
	computed: {
		isFullList() {
			return this.Full !== null
				&& this.Full.panelType == PanelType.ListPanel;
		},
		isTopList() {
			return this.Top !== null
				&& this.Top.panelType == PanelType.ListPanel;
		},
		isBottomList() {
			return this.Bottom !== null
				&& this.Bottom.panelType == PanelType.ListPanel;
		},
		isFullInfo() {
			return this.Full !== null
				&& this.Full.panelType == PanelType.InfoPanel;
		},
		isTopInfo() {
			return this.Top !== null
				&& this.Top.panelType == PanelType.InfoPanel;
		},
		isBottomInfo() {
			return this.Bottom !== null
				&& this.Bottom.panelType == PanelType.InfoPanel;
		},
	},
	methods: {
		onResize() {
			this.onlyFull = window.innerHeight < 500;
		},
		initSplit() {
			if(this.isFullFront == false
				&& this.hasSplit()) {
				const loc = this;
				this.split = Split(['#panTop', '#panBottom'], {
					direction: 'vertical',
					sizes: [this.topHeight, 100 - this.topHeight],
					minSize: 100,
					gutterSize: 6,
					onDragEnd: function(sizes) {
						loc.topHeight = sizes[0];
						window.SegMap.SaveRoute.UpdateRoute();
					},
				});
			}
		},
		arrangeSplitter() {
			if(this.isFullFront == false
				&& this.hasSplit()) {
					this.initSplit();
			} else {
				if(this.split == null) {
					return;
				}
				this.split.destroy();
			}
		},
		hasAny() {
			return this.Top !== null
				|| this.Bottom !== null
				|| this.Full !== null;
		},
		hasSplit() {
			return this.Top !== null
				&& this.Bottom !== null;
		},
		getLocated(fid) {
			if(this.Top !== null
				&& this.Top.Key.Id === fid) {
				return 'Top';
			}
			if(this.Bottom !== null
				&& this.Bottom.Key.Id === fid) {
				return 'Bottom';
			}
			if(this.Full !== null
				&& this.Full.Key.Id === fid) {
				return 'Full';
			}
			return null;
		},
		Add(panelInfo, index) {
			this.isFullFront = true;
			this.doAdd(panelInfo, 'Full', index);
		},
		AddTop(panelInfo, index) {
			this.isFullFront = false;
			this.doAdd(panelInfo, 'Top', index);
		},
		AddBottom(panelInfo, index) {
			this.isFullFront = false;
			if(this.Top !== null
				&& this.Top.Key.Id === panelInfo.Key.Id) {
				if(index !== undefined
					&& this.Top.panelType == PanelType.ListPanel) {
					this.Top.detailIndex = index;
				}
				return;
			}
			this.doAdd(panelInfo, 'Bottom', index);
		},
		doAdd(panelInfo, panelPositionEnum, index) {
			if(this.onlyFull) {
				panelPositionEnum = 'Full';
				this.isFullFront = true;
				this.Top = null;
				this.Bottom = null;
			}

			this.hasContent = true;
			this.collapsed = false;
			this[panelPositionEnum] = panelInfo;

			if(index !== undefined
				&& this[panelPositionEnum].panelType == PanelType.ListPanel) {
				this[panelPositionEnum].detailIndex = index;
			}
			this.arrangePanels();
		},
		arrangePanels() {
			if(this.collapsed || this.hasAny() == false) {
				return;
			}
			if(this.Top === null
				&& this.Bottom !== null) {
				this.Top = this.Bottom;
				this.Bottom = null;
			}
			this.arrangeSplitter();
		},
		doClose(e, fid) {
			let panelPositionEnum = this.getLocated(fid);
			if(panelPositionEnum === null) {
				return;
			}
			this[panelPositionEnum] = null;

			if(this.Full === null) {
				this.isFullFront = false;
			}

			if(this.Top === null
				&& this.Bottom === null) {
				this.isFullFront = true;
			}
			if(this.hasAny() == false) {
				this.hasContent = false;
				this.collapsed = true;
			}
			this.arrangePanels();
			window.SegMap.SaveRoute.UpdateRoute();
		},
		doToggle() {
			this.collapsed = ! this.collapsed;
		},
		updateMapTypeControl() {
			var css1 = h.getCssRule(document, '.gm-style-mtc:first-of-type');
			var css2 = h.getCssRule(document, '.gm-style-mtc');
			var css3 = h.getCssRule(document, '.gm-style-mtc:last-of-type');

			if(css1 === null) {
				css1 = { style: { transform: '' } };
				css2 = { style: { transform: '' } };
				css3 = { style: { transform: '' } };
			}
			if (this.collapsed) {
				window.SegMap.SetTypeControlsDefault();
				css1.style.transform = 'translateX(9px) scale(0.8)';
				css2.style.transform = 'translateX(-8px) scale(0.8)';
				css3.style.transform = 'translateX(-26px) scale(0.8)';
			} else {
				window.SegMap.SetTypeControlsDropDown();
				css2.style.transform = 'translate(' + (this.width + 7) + 'px) scale(0.85)';
				css1.style.transform = '';
				css3.style.transform = '';
			}
		},
		setCss(el, collapsed, onValue, offValue) {
			if (collapsed) {
				Object.assign(el.style, offValue);
			} else {
				Object.assign(el.style, onValue);
			}
		},
		updateSuroundings(cssClass, onValue, offValue) {
			let el = document.getElementsByClassName(cssClass);
			if (el.length > 0) {
				this.setCss(el[0], this.collapsed, onValue, offValue);
			} else {
				const loc = this;
				var checkExist = setInterval(function() {
					let el = document.getElementsByClassName(cssClass);
					if (el.length > 0) {
						clearInterval(checkExist);
						loc.setCss(el[0], loc.collapsed, onValue, offValue);
					}
				}, 50);
			}
		},
	},
	watch: {
		onlyFull() {
			if(this.onlyFull) {
				if(this.Full == null) {
					this.Full = this.Top;
				}
				this.isFullFront = true;
				this.Top = null;
				this.Bottom = null;
				this.arrangePanels();
			}
		},
		collapsed() {
			this.arrangePanels();
			this.updateMapTypeControl();
			this.updateSuroundings('fab-wrapper',
				{ transform: 'translate('+ this.width + 'px)' },
				{ transform: '' }
			);
			this.updateSuroundings('edit-button',
				{ transform: 'translate(' + this.width + 'px)' },
				{ transform: '' }
			);
			this.updateSuroundings('searchBar',
				{ left: (this.width + 200) + 'px', width: 'calc(100% - 700px)' },
				{ left: this.width + 'px', width: 'calc(100% - 500px)' }
			);
		},
	},
};
</script>

<style scoped>
.left-panelPositionEnum {
	position:absolute;
	height:100%;
	left:0;
	top:0;
	overflow-y: auto;
	box-shadow: 0 0 20px rgba(0, 0, 0, 0.3);
	z-index:1;
	background-color: white;
}
.fade-enter-active, .fade-leave-active {
	transition: opacity .35s;
}
.fade-enter, .fade-leave-to {
	opacity: 0;
}
</style>

