const str = require('./str');
const login = require('./redirectLogin');

module.exports = {
	err(method, err) {
		if (window.Context) {
			window.Context.ErrorSignaled.value++;
		}
		if(err !== undefined) {
			if(err.message && err.message !== 'cancelled') {
				this.HandleError(err);
			}
		} else {
			console.error('Error', method);
		}
	},
	errDialog(method, text, err) {
		var msg = '';
		if (err !== undefined) {
			if (err.response && err.response.status === 403)
			{
				alert('La sesión ya no se encuentra activa. Deberá volver a identificarse para poder continuar.');
				login.redirectLogin();
				return;
			}
			if (err.message) {
				msg = err.message;
			}
			if (msg !== 'cancelled') {
				this.HandleError(err);
			}
		} else {
			console.error('Error', method);
		}
		if (msg === 'cancelled') {
			return;
		}
		if (window.Context) {
			window.Context.ErrorSignaled.value++;
		}
		var pre = '';
		if (msg === 'Network Error') {
			pre = 'No hay una conexión disponible para completar la solicitud.\n\nVerifique su acceso a internet e intente nuevamente.';
			alert(pre);
			return;
		} else if (msg === 'Request failed with status code 500') {
			pre = 'No ha sido posible ';
		} else if (msg === 'Request failed with status code 501' || msg === 'Request failed with status code 502' || msg === 'Request failed with status code 503') {
			pre = 'El servidor no se encuentra disponible. En consecuencia, no fue posible ';
		} else if (msg === 'Request failed with status code 401' || msg === 'Request failed with status code 403') {
			pre = 'El usuario actual no posee los permisos suficientes para ';
		} else if (msg === 'Request failed with status code 404') {
			pre = 'Mientras realizamos tareas de mantenimiento en el sitio no es posible realizar operaciones de edición sobre información. Por favor, vuelva a intentar más tarde para ';
		} else if (msg === 'Request failed with status code 405') {
			pre = 'Se ha utilizado un método HTTP (post, get, etc) no aceptado por el servidor al ';
		} else {
			pre = 'No fue posible ';
		}
		var post = '';
		if (err.response && err.response.data) {
			var msgtext = err.response.data.trim();
			if (msgtext.startsWith('[ME-E]:')) {
				post = ' ' + msgtext.substr(7);
				if (!post.endsWith('.')) {
					post += '.';
				}
			}
		}
		// Pone el mensaje visual
		alert(str.AddDot(pre + text) + post + '\n\nSi el problema persiste, póngase en contacto con soporte para que podamos solucionar el inconveniente.');
	},
	errMessage(method, errMessage) {
		if (errMessage && errMessage !== 'cancelled') {
			console.log('Error', method, errMessage);
		}
	},
	HandleError(ex, vm, info) {
		// var errorData = {
		// 	name: ex.name, // e.g. ReferenceError
		// 	message: ex.line, // e.g. x is undefined
		// 	url: document.location.href,
		// 	stack: ex.stack // stacktrace string; remember, different per-browser!
		// };
		//
		// // enviar a https://rollbar.com/
		// $.post('/logger/js/', {
		// 	data: errorData
		// });

		console.error('Error en Mapas:', ex);
		return false;
	},
};

