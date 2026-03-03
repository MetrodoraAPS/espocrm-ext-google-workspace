import OidcLoginHandler from 'handlers/login/oidc';

class GoogleWorkspaceLoginHandler extends OidcLoginHandler {
    process() {
        const proxy = window.open('about:blank', 'ConnectWithGoogle', 'location=0,status=0,width=600,height=600');
        Espo.Ui.notifyWait();

        return new Promise((resolve, reject) => {
            Espo.Ajax.getRequest('GoogleWorkspace/authorizationData')
                .then(data => {
                    Espo.Ui.notify(false);

                    this.processWithData(data, proxy)
                        .then(info => {
                            const authString = btoa('**google-workspace:' + info.code);
                            resolve({
                                'Espo-Authorization': authString,
                                'Authorization': 'Basic ' + authString
                            });
                        })
                        .catch(() => {
                            proxy.close();
                            reject();
                        });
                })
                .catch(() => {
                    Espo.Ui.notify(false);
                    proxy.close();
                    reject();
                });
        });
    }
    
    processWithData(data, proxy) {
        const state = (Math.random() + 1).toString(36).substring(4);
        const nonce = (Math.random() + 1).toString(36).substring(4);

        const params = {
            client_id: data.clientId,
            redirect_uri: data.redirectUri,
            response_type: 'code',
            scope: data.scopes.join(' '),
            state: state,
            nonce: nonce,
        };

        if (data.prompt) {
            params.prompt = data.prompt;
        }

        if (data.hd) {
            params.hd = data.hd;
        }

        const partList = Object.entries(params)
            .map(([key, value]) => {
                return key + '=' + encodeURIComponent(value);
            });

        // Use ? or & depending on if endpoint already has ? (like when we appended hd=)
        const joiner = data.endpoint.indexOf('?') === -1 ? '?' : '&';
        const url = data.endpoint + joiner + partList.join('&');

        return this.processWindow(url, state, nonce, proxy);
    }
}

export default GoogleWorkspaceLoginHandler;
