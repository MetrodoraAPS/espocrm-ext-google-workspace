import OidcLoginHandler from 'handlers/login/oidc';

class GoogleWorkspaceLoginHandler extends OidcLoginHandler {
    async process() {
        const proxy = window.open('about:blank', 'ConnectWithGoogle', 'location=0,status=0,width=600,height=600');
        Espo.Ui.notifyWait();

        try {
            const data = await Espo.Ajax.getRequest('GoogleWorkspace/authorizationData');
            Espo.Ui.notify(false);

            const info = await this.processWithData(data, proxy);
            const authString = btoa('**google-workspace:' + info.code);
            
            return {
                'Espo-Authorization': authString,
                'Authorization': 'Basic ' + authString
            };
        } catch (error) {
            Espo.Ui.notify(false);
            proxy.close();
            throw error;
        }
    }
    
    processWithData(data, proxy) {
        const state = (Math.random() + 1).toString(36).substring(4);
        const nonce = (Math.random() + 1).toString(36).substring(4);

        const params = new URLSearchParams({
            client_id: data.clientId,
            redirect_uri: data.redirectUri,
            response_type: 'code',
            scope: data.scopes.join(' '),
            state: state,
            nonce: nonce,
        });

        if (data.prompt) {
            params.append('prompt', data.prompt);
        }

        if (data.hd) {
            params.append('hd', data.hd);
        }

        const joiner = data.endpoint.indexOf('?') === -1 ? '?' : '&';
        const url = data.endpoint + joiner + params.toString();

        return this.processWindow(url, state, nonce, proxy);
    }
}

export default GoogleWorkspaceLoginHandler;
