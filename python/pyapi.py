'''
Web service module for interaction with CLEVERGY python modules
@author: Markus Schafroth (markus.schafroth@3084.ch)
'''

#%%Import Statements
# import web service functionality
import flask, json
from flask import jsonify, request

# import our own modules
from modules.SolRad import SolRad

app = flask.Flask(__name__)
app.config["DEBUG"] = False
solrad = None

"""
Service providing human readable information
"""
@app.route('/', methods=['GET'])
def home():
    response = '<h1>Clevergy.fi Python API</h1>For detailed instructions, please refer to the Readme.'
    return response

"""
Service providing status information regarding the availability of the REST service
"""
@app.route('/status', methods=['GET'])
def status():
    response = {'value': True, 'text': "up and running"}
    return jsonify(response)

"""
Model training: PV power
"""
@app.route('/solrad/p_training', methods=['POST'])
def ptraining():
    global solrad
    data = request.get_json('data')
    #try:
    if True:
        solrad.train(data)
        response = {'success': True, 'text': "training succeeded"}
    #except:
    #    response = {'success': False, 'text': "training failed"}
    return response

"""
Get predicted Power values based on input data
"""
@app.route('/solrad/p_prediction', methods=['POST'])
def pprediction():
    global solrad
    input = request.get_json()
    #try:
    if True:
        prediction = solrad.predict(input)
        if prediction is not None:
            response = json.dumps(prediction.tolist())
        else:
            response = {'success': False, 'text': "prediction failed: no training data available"}
    #except:
        #response = {'success': False, 'text': "prediction failed: general exception"}

    return response

# run the web service
if __name__ == "__main__":
    solrad = SolRad()
    app.run(debug = False, host="0.0.0.0", port=8192)
