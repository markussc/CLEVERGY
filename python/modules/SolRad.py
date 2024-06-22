import pandas
from sklearn import linear_model
from io import StringIO

class SolRad:
    regr = None

    """
    Train the predictor model using existing data
    """
    def train(self, data):
        df = pandas.DataFrame(data) #read_json(StringIO(data))

        X = df[['sunElevation', 'sunAzimuth', 'cloudiness', 'temperature', 'humidity', 'rain', 'snow']]
        y = df['power']

        self.regr = linear_model.LinearRegression()
        self.regr.fit(X.values, y)
        return

    """
    Predict power based on input and available predictor model
    """
    def predict(self, input):
        #predict the PV power based on the passed variables:
        if self.regr is not None:
            predictedPower = self.regr.predict(input)
        else:
            predictedPower = None
        return predictedPower
